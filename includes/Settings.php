<?php
/**
 * Plugin settings (shared AI settings live in Core3\Core\Settings_Store). Stripe secrets are kept in
 * their own encrypted option (Payments\Stripe), never in this array, because the core settings
 * endpoint returns the whole array to the admin app.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking;

use Dev3\AiBooking\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3bk_settings_defaults / dev3bk_settings_sanitize.
 */
class Settings {

	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Default weekly hours: Mon–Fri 09:00–17:00.
	 *
	 * @return array
	 */
	public static function default_hours() {
		$out = array();
		foreach ( self::DAYS as $day ) {
			$out[ $day ] = in_array( $day, array( 'sat', 'sun' ), true ) ? array() : array( array( '09:00', '17:00' ) );
		}
		return $out;
	}

	/**
	 * Adds plugin defaults. No translated strings here (read before init).
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'business_name'           => '',
				'business_email'          => '',
				'timezone'                => '',
				'time_format'             => '24',
				'week_start'              => 1,
				'slot_interval'           => 30,
				'min_notice'              => 120,
				'max_advance'             => 60,
				'default_status'          => 'confirmed',
				'hold_minutes'            => 15,
				'business_hours'          => self::default_hours(),
				'business_breaks'         => array(),
				'phone_field'             => 'optional',
				'notes_field'             => true,
				'allow_cancel'            => true,
				'allow_reschedule'        => true,
				'cancel_cutoff_hours'     => 24,
				'reschedule_cutoff_hours' => 24,
				'max_reschedules'         => 2,
				'refund_window_hours'     => 24,
				'auto_refund'             => true,
				'policy_text'             => '',
				'currency'                => 'USD',
				'pay_later'               => true,
				'stripe_enabled'          => false,
				'woo_enabled'             => false,
				'reminder1_hours'         => 24,
				'reminder2_hours'         => 2,
				'followup_hours'          => 0,
				'admin_notify'            => true,
				'staff_notify'            => true,
				'accent_color'            => '#4f46e5',
				'ai_nl'                   => true,
				'ai_risk'                 => true,
				'booking_page'            => 0,
				'account_page'            => 0,
			)
		);
	}

	/**
	 * Sanitizes a weekly hours map: day => [ [ 'HH:MM', 'HH:MM' ], … ], sorted, non-overlapping, start < end.
	 *
	 * @param mixed $value Raw.
	 * @return array
	 */
	public static function sanitize_week( $value ) {
		$value = is_array( $value ) ? $value : array();
		$out   = array();
		foreach ( self::DAYS as $day ) {
			$out[ $day ] = self::sanitize_intervals( $value[ $day ] ?? array() );
		}
		return $out;
	}

	/**
	 * Sanitizes a list of [start, end] times, merging overlaps.
	 *
	 * @param mixed $pairs Raw.
	 * @return array
	 */
	public static function sanitize_intervals( $pairs ) {
		$rows = array();
		foreach ( (array) $pairs as $pair ) {
			if ( is_array( $pair ) && isset( $pair['start'], $pair['end'] ) ) {
				$pair = array( $pair['start'], $pair['end'] );
			}
			if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
				continue;
			}
			$start = self::time( $pair[0] );
			$end   = self::time( $pair[1] );
			if ( null === $start || null === $end ) {
				continue;
			}
			if ( '00:00' === $end ) {
				$end = '24:00';
			}
			if ( $start >= $end ) {
				continue;
			}
			$rows[] = array( $start, $end );
		}
		usort( $rows, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		$merged = array();
		foreach ( $rows as $row ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $row[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $row[1] );
				continue;
			}
			$merged[] = $row;
		}
		return array_slice( $merged, 0, 8 );
	}

	/**
	 * Normalises "9:5", "09:05", "24:00" to HH:MM, or null.
	 *
	 * @param mixed $value Raw.
	 * @return string|null
	 */
	public static function time( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $value ), $m ) ) {
			return null;
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( 24 === $h && 0 === $i ) {
			return '24:00';
		}
		if ( $h > 23 || $i > 59 ) {
			return null;
		}
		return sprintf( '%02d:%02d', $h, $i );
	}

	/**
	 * Valid IANA timezone or ''.
	 *
	 * @param mixed $tz Raw.
	 * @return string
	 */
	public static function sanitize_tz( $tz ) {
		$tz = trim( (string) $tz );
		if ( '' === $tz ) {
			return '';
		}
		return in_array( $tz, timezone_identifiers_list(), true ) || 'UTC' === $tz ? $tz : '';
	}

	/**
	 * Business timezone (setting, else the site timezone).
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		$tz = self::sanitize_tz( self::get( 'timezone', '' ) );
		return '' !== $tz ? new \DateTimeZone( $tz ) : wp_timezone();
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$ints = array(
			'week_start'              => array( 0, 1 ),
			'slot_interval'           => array( 5, 240 ),
			'min_notice'              => array( 0, 60 * 24 * 60 ),
			'max_advance'             => array( 1, 730 ),
			'hold_minutes'            => array( 5, 120 ),
			'cancel_cutoff_hours'     => array( 0, 24 * 60 ),
			'reschedule_cutoff_hours' => array( 0, 24 * 60 ),
			'max_reschedules'         => array( 0, 20 ),
			'refund_window_hours'     => array( 0, 24 * 60 ),
			'reminder1_hours'         => array( 0, 24 * 14 ),
			'reminder2_hours'         => array( 0, 24 * 14 ),
			'followup_hours'          => array( 0, 24 * 14 ),
			'booking_page'            => array( 0, PHP_INT_MAX ),
			'account_page'            => array( 0, PHP_INT_MAX ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = (int) min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		$flags = array( 'notes_field', 'allow_cancel', 'allow_reschedule', 'auto_refund', 'pay_later', 'stripe_enabled', 'woo_enabled', 'admin_notify', 'staff_notify', 'ai_nl', 'ai_risk' );
		foreach ( $flags as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['business_name'] ) ) {
			$out['business_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['business_name'] ) ), 0, 120 );
		}
		if ( isset( $input['business_email'] ) ) {
			$email                 = sanitize_email( (string) $input['business_email'] );
			$out['business_email'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['timezone'] ) ) {
			$out['timezone'] = self::sanitize_tz( $input['timezone'] );
		}
		if ( isset( $input['time_format'] ) ) {
			$out['time_format'] = '12' === (string) $input['time_format'] ? '12' : '24';
		}
		if ( isset( $input['default_status'] ) ) {
			$out['default_status'] = 'pending' === $input['default_status'] ? 'pending' : 'confirmed';
		}
		if ( isset( $input['phone_field'] ) && in_array( $input['phone_field'], array( 'required', 'optional', 'hidden' ), true ) ) {
			$out['phone_field'] = $input['phone_field'];
		}
		if ( isset( $input['business_hours'] ) ) {
			$out['business_hours'] = self::sanitize_week( $input['business_hours'] );
		}
		if ( isset( $input['business_breaks'] ) ) {
			$out['business_breaks'] = self::sanitize_week( $input['business_breaks'] );
		}
		if ( isset( $input['policy_text'] ) ) {
			$out['policy_text'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['policy_text'] ) ), 0, 1000 );
		}
		if ( isset( $input['currency'] ) ) {
			$currency        = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $input['currency'] ) );
			$out['currency'] = 3 === strlen( $currency ) ? $currency : 'USD';
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		return $out;
	}

	/**
	 * Currency in use (WooCommerce's when Woo payments are on).
	 *
	 * @return string
	 */
	public static function currency() {
		if ( self::get( 'woo_enabled' ) && function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}
		return (string) self::get( 'currency', 'USD' );
	}

	/**
	 * Formats money without HTML.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		$currency = $currency ? $currency : self::currency();
		$symbols  = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'AUD' => 'A$',
			'CAD' => 'C$',
			'INR' => '₹',
			'JPY' => '¥',
			'AED' => 'AED ',
		);
		$decimals = in_array( $currency, array( 'JPY', 'KRW' ), true ) ? 0 : 2;
		$number   = number_format_i18n( (float) $amount, $decimals );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $number . ' ' . $currency;
	}
}
