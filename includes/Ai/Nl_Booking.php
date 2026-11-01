<?php
/**
 * DEV3 AI natural-language booking: text → constraints (Gemini, or the rule parser as fallback) →
 * validated → the availability engine → only real free slots that match.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Ai;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Core3\Api\Ai;
use Dev3\AiBooking\Core3\Api\Prompts;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * NL booking.
 */
class Nl_Booking {

	/**
	 * Suggests real slots for a request.
	 *
	 * @param string $text    Request.
	 * @param array  $args    service_id, staff_id (0 = any), tz, limit.
	 * @param string $context storefront|admin.
	 * @return array|\WP_Error { source, constraints, summary, slots[], relaxed, needs }
	 */
	public static function suggest( $text, array $args, $context = 'storefront' ) {
		$text = trim( mb_substr( sanitize_textarea_field( (string) $text ), 0, 300 ) );
		if ( '' === $text ) {
			return new \WP_Error( 'dev3bk_empty', __( 'Tell us when suits you, for example "Tuesday after 5".', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$tz    = Clock::zone( (string) ( $args['tz'] ?? '' ) );
		$today = Clock::date( Clock::now(), $tz );
		$ctx   = self::context( $today );
		$rules = Nl_Parser::parse( $text, $ctx );

		$source      = 'rules';
		$constraints = $rules;
		if ( Settings::get( 'ai_nl', true ) && Ai::enabled( $context ) ) {
			$result = Prompts::run(
				'nl_booking',
				array(
					'today'    => $today,
					'weekday'  => Clock::format( Clock::now(), $tz, 'l' ),
					'tz'       => $tz->getName(),
					'services' => array_map(
						static fn( $s ) => array(
							'id'   => $s['id'],
							'name' => $s['name'],
						),
						$ctx['services']
					),
					'staff'    => array_map(
						static fn( $s ) => array(
							'id'   => $s['id'],
							'name' => $s['name'],
						),
						$ctx['staff']
					),
					'text'     => $text,
				),
				$context
			);
			if ( ! is_wp_error( $result ) && is_array( $result['data'] ?? null ) ) {
				$constraints = self::merge( $result['data'], $rules );
				$source      = 'ai';
			}
		}

		$service_id = (int) ( $args['service_id'] ?? 0 );
		$staff_arg  = (int) ( $args['staff_id'] ?? 0 );
		$c          = self::validate( $constraints, $today, $service_id, $staff_arg );
		$out        = array(
			'source'      => $source,
			'constraints' => $c,
			'summary'     => '' !== $c['summary'] ? $c['summary'] : Nl_Parser::summary( $c, $ctx ),
			'slots'       => array(),
			'relaxed'     => false,
			'needs'       => '',
		);
		if ( ! $c['service_id'] ) {
			$out['needs'] = 'service';
			return $out;
		}
		$service      = Services::find( $c['service_id'] );
		$limit        = min( 24, max( 1, (int) ( $args['limit'] ?? 12 ) ) );
		$out['slots'] = self::match( $service, $c, $tz, $limit );
		if ( ! $out['slots'] && ( $c['time_from'] || $c['time_to'] || $c['weekdays'] ) ) {
			$loose          = array_merge(
				$c,
				array(
					'time_from' => '',
					'time_to'   => '',
					'weekdays'  => array(),
				)
			);
			$out['slots']   = self::match( $service, $loose, $tz, min( 6, $limit ) );
			$out['relaxed'] = (bool) $out['slots'];
		}
		if ( ! $out['slots'] ) {
			$next = Engine::next_available( $service, $c['staff_id'], $c['date_to'], $tz->getName() );
			if ( $next ) {
				$out['slots']   = self::match(
					$service,
					array_merge(
						$c,
						array(
							'date_from' => $next,
							'date_to'   => $next,
							'time_from' => '',
							'time_to'   => '',
							'weekdays'  => array(),
						)
					),
					$tz,
					min( 6, $limit )
				);
				$out['relaxed'] = true;
			}
		}
		return $out;
	}

	/**
	 * Services + staff names for the prompt and the parser.
	 *
	 * @param string $today Y-m-d.
	 * @return array
	 */
	public static function context( $today ) {
		return array(
			'today'    => $today,
			'services' => array_map(
				static fn( $s ) => array(
					'id'   => (int) $s['id'],
					'name' => $s['name'],
				),
				Services::all( true )
			),
			'staff'    => array_map(
				static fn( $s ) => array(
					'id'   => (int) $s['id'],
					'name' => $s['name'],
				),
				Staff::all( true )
			),
			'window'   => 14,
		);
	}

	/**
	 * AI constraints, falling back to rule values for fields the model left blank/invalid.
	 *
	 * @param array $ai    AI data.
	 * @param array $rules Rule constraints.
	 * @return array
	 */
	public static function merge( array $ai, array $rules ) {
		$out = $rules;
		foreach ( array( 'service_id', 'staff_id' ) as $k ) {
			if ( isset( $ai[ $k ] ) && (int) $ai[ $k ] > 0 ) {
				$out[ $k ] = (int) $ai[ $k ];
			}
		}
		foreach ( array( 'date_from', 'date_to' ) as $k ) {
			if ( isset( $ai[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ai[ $k ] ) ) {
				$out[ $k ] = (string) $ai[ $k ];
			}
		}
		if ( isset( $ai['weekdays'] ) && is_array( $ai['weekdays'] ) ) {
			$out['weekdays'] = $ai['weekdays'];
		}
		foreach ( array( 'time_from', 'time_to' ) as $k ) {
			if ( isset( $ai[ $k ] ) ) {
				$out[ $k ] = (string) $ai[ $k ];
			}
		}
		$out['summary'] = isset( $ai['summary'] ) ? sanitize_text_field( (string) $ai['summary'] ) : '';
		return $out;
	}

	/**
	 * Validates constraints against real data: unknown ids are ignored, dates are clamped to the
	 * booking window, times must be HH:MM.
	 *
	 * @param array  $c          Constraints.
	 * @param string $today      Y-m-d (customer tz).
	 * @param int    $service_id Service chosen in the widget (wins).
	 * @param int    $staff_id   Staff chosen in the widget (wins).
	 * @return array
	 */
	public static function validate( array $c, $today, $service_id = 0, $staff_id = 0 ) {
		$service = $service_id ? Services::find( $service_id ) : null;
		if ( ! $service || 'active' !== $service['status'] ) {
			$service = ! empty( $c['service_id'] ) ? Services::find( (int) $c['service_id'] ) : null;
			$service = $service && 'active' === $service['status'] ? $service : null;
		}
		$sid   = $service ? (int) $service['id'] : 0;
		$staff = $staff_id ? $staff_id : (int) ( $c['staff_id'] ?? 0 );
		if ( $staff && ( ! Staff::get( $staff ) || ( $sid && ! Staff::performs( $staff, $sid ) ) ) ) {
			$staff = 0;
		}
		$max   = $service ? Services::max_advance( $service ) : (int) Settings::get( 'max_advance', 60 );
		$limit = gmdate( 'Y-m-d', strtotime( $today . ' +' . $max . ' day UTC' ) );
		$from  = \Dev3\AiBooking\Data\Exceptions::date( $c['date_from'] ?? '' );
		$to    = \Dev3\AiBooking\Data\Exceptions::date( $c['date_to'] ?? '' );
		$from  = $from && $from >= $today ? $from : $today;
		$from  = min( $from, $limit );
		$to    = $to && $to >= $from ? $to : gmdate( 'Y-m-d', strtotime( $from . ' +13 day UTC' ) );
		$to    = min( $to, $limit, gmdate( 'Y-m-d', strtotime( $from . ' +31 day UTC' ) ) );
		$days  = array();
		foreach ( (array) ( $c['weekdays'] ?? array() ) as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$days[ $d ] = $d;
			}
		}
		$days = array_values( $days );
		sort( $days );
		if ( 7 === count( $days ) ) {
			$days = array();
		}
		$tf = Settings::time( (string) ( $c['time_from'] ?? '' ) );
		$tt = Settings::time( (string) ( $c['time_to'] ?? '' ) );
		if ( $tf && $tt && $tf >= $tt ) {
			$tt = null;
		}
		return array(
			'service_id' => $sid,
			'staff_id'   => $staff,
			'date_from'  => $from,
			'date_to'    => $to,
			'weekdays'   => $days,
			'time_from'  => (string) $tf,
			'time_to'    => (string) $tt,
			'summary'    => mb_substr( sanitize_text_field( (string) ( $c['summary'] ?? '' ) ), 0, 160 ),
		);
	}

	/**
	 * Engine slots filtered by weekday and start-time window (customer local time).
	 *
	 * @param array         $service Service.
	 * @param array         $c       Constraints.
	 * @param \DateTimeZone $tz      Customer zone.
	 * @param int           $limit   Max slots.
	 * @return array [ { date, day_label, start, label, staff[] } ]
	 */
	public static function match( array $service, array $c, \DateTimeZone $tz, $limit ) {
		$result = Engine::days( $service, (int) $c['staff_id'], $c['date_from'], $c['date_to'], $tz->getName() );
		$out    = array();
		foreach ( $result['days'] as $date => $slots ) {
			$dow = (int) ( new \DateTimeImmutable( $date ) )->format( 'N' );
			if ( $c['weekdays'] && ! in_array( $dow, $c['weekdays'], true ) ) {
				continue;
			}
			foreach ( $slots as $slot ) {
				$hm = Clock::format( Clock::parse( $slot['start'] ), $tz, 'H:i' );
				if ( ( $c['time_from'] && $hm < $c['time_from'] ) || ( $c['time_to'] && $hm >= $c['time_to'] ) ) {
					continue;
				}
				$out[] = array(
					'date'      => $date,
					'day_label' => wp_date( 'D j M', strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ),
					'start'     => $slot['start'],
					'label'     => $slot['label'],
					'staff'     => $slot['staff'],
				);
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}
}
