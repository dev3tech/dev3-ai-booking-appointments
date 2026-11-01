<?php
/**
 * Email templates. Defaults ship in code; edits (including accepted DEV3 AI drafts) are stored as
 * the approved version in the dev3bk_templates option. Only approved text is ever sent.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Notify;

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
class Templates {

	const OPTION = 'dev3bk_templates';

	const PLACEHOLDERS = array( 'customer_name', 'service', 'staff', 'date', 'time', 'timezone', 'duration', 'location', 'price', 'amount_due', 'ref', 'manage_url', 'ics_url', 'google_url', 'site_name', 'policy' );

	/**
	 * Template definitions (id => meta + default copy).
	 *
	 * @return array
	 */
	public static function definitions() {
		$sign = "\n\n" . __( 'See you soon,', 'dev3-ai-booking-appointments' ) . "\n{site_name}";
		return array(
			'customer_confirmed'   => array(
				'label'    => __( 'Booking confirmed', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'customer',
				'required' => array( 'manage_url' ),
				'subject'  => __( 'Your booking is confirmed: {service} on {date}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nYour {service} with {staff} is confirmed for {date} at {time} ({timezone}).\n\nNeed to change something? Manage your booking here: {manage_url}", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'customer_pending'     => array(
				'label'    => __( 'Booking received (pending approval)', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'customer',
				'required' => array( 'manage_url' ),
				'subject'  => __( 'We received your booking request: {service} on {date}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nThanks for your request for {service} on {date} at {time} ({timezone}). We will confirm it shortly.\n\nYour booking: {manage_url}", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'customer_rescheduled' => array(
				'label'    => __( 'Booking rescheduled', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'customer',
				'required' => array( 'manage_url' ),
				'subject'  => __( 'Your booking has moved: {service} on {date}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nYour {service} with {staff} is now on {date} at {time} ({timezone}).\n\nManage your booking: {manage_url}", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'customer_cancelled'   => array(
				'label'    => __( 'Booking cancelled', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'customer',
				'required' => array(),
				'subject'  => __( 'Your booking was cancelled: {service} on {date}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nYour {service} on {date} at {time} has been cancelled. We hope to see you another time.", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'reminder_1'           => array(
				'label'    => __( 'Reminder 1', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'reminder',
				'required' => array( 'manage_url' ),
				'subject'  => __( 'Reminder: {service} on {date} at {time}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nThis is a friendly reminder of your {service} with {staff} on {date} at {time} ({timezone}).\n\nCan't make it? Please reschedule or cancel here so someone else can take the slot: {manage_url}", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'reminder_2'           => array(
				'label'    => __( 'Reminder 2', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'reminder',
				'required' => array( 'manage_url' ),
				'subject'  => __( 'See you soon: {service} at {time}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nYour {service} with {staff} starts at {time} today. {location}\n\nRunning late or need to change? {manage_url}", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'followup'             => array(
				'label'    => __( 'Follow-up after the visit', 'dev3-ai-booking-appointments' ),
				'audience' => 'customer',
				'group'    => 'reminder',
				'required' => array(),
				'subject'  => __( 'Thanks for visiting {site_name}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Hi {customer_name},\n\nThank you for your {service} with {staff}. We would love to see you again.", 'dev3-ai-booking-appointments' ) . $sign,
			),
			'admin_new'            => array(
				'label'    => __( 'New booking (admin & staff)', 'dev3-ai-booking-appointments' ),
				'audience' => 'admin',
				'group'    => 'admin',
				'required' => array(),
				'subject'  => __( 'New booking {ref}: {service} on {date} at {time}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "New booking {ref}\n\nCustomer: {customer_name}\nService: {service}\nStaff: {staff}\nWhen: {date} at {time} ({timezone})\nPrice: {price}", 'dev3-ai-booking-appointments' ),
			),
			'admin_cancelled'      => array(
				'label'    => __( 'Booking cancelled (admin & staff)', 'dev3-ai-booking-appointments' ),
				'audience' => 'admin',
				'group'    => 'admin',
				'required' => array(),
				'subject'  => __( 'Cancelled {ref}: {service} on {date}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Booking {ref} was cancelled.\n\nCustomer: {customer_name}\nService: {service}\nStaff: {staff}\nWhen: {date} at {time} ({timezone})", 'dev3-ai-booking-appointments' ),
			),
			'admin_rescheduled'    => array(
				'label'    => __( 'Booking rescheduled (admin & staff)', 'dev3-ai-booking-appointments' ),
				'audience' => 'admin',
				'group'    => 'admin',
				'required' => array(),
				'subject'  => __( 'Moved {ref}: {service} now on {date} at {time}', 'dev3-ai-booking-appointments' ),
				'body'     => __( "Booking {ref} was rescheduled.\n\nCustomer: {customer_name}\nService: {service}\nStaff: {staff}\nNew time: {date} at {time} ({timezone})", 'dev3-ai-booking-appointments' ),
			),
		);
	}

	/**
	 * Stored overrides.
	 *
	 * @return array
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Approved template (default copy unless an approved edit exists).
	 *
	 * @param string $id Template id.
	 * @return array|null { id, label, audience, group, required, subject, body, enabled, customized, source, approved_at }
	 */
	public static function get( $id ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $id ] ) ) {
			return null;
		}
		$def    = $defs[ $id ];
		$stored = self::stored()[ $id ] ?? array();
		return array(
			'id'              => $id,
			'label'           => $def['label'],
			'audience'        => $def['audience'],
			'group'           => $def['group'],
			'required'        => $def['required'],
			'subject'         => isset( $stored['subject'] ) ? (string) $stored['subject'] : $def['subject'],
			'body'            => isset( $stored['body'] ) ? (string) $stored['body'] : $def['body'],
			'default_subject' => $def['subject'],
			'default_body'    => $def['body'],
			'enabled'         => isset( $stored['enabled'] ) ? (bool) $stored['enabled'] : ( 'followup' !== $id ),
			'customized'      => isset( $stored['subject'] ) || isset( $stored['body'] ),
			'source'          => (string) ( $stored['source'] ?? 'default' ),
			'approved_at'     => (string) ( $stored['approved_at'] ?? '' ),
		);
	}

	/**
	 * All templates.
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();
		foreach ( array_keys( self::definitions() ) as $id ) {
			$out[] = self::get( $id );
		}
		return $out;
	}

	/**
	 * Removes unknown {placeholders} and returns the missing required ones.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_unknown( $text ) {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static fn( $m ) => in_array( $m[1], self::PLACEHOLDERS, true ) ? $m[0] : '',
			(string) $text
		);
	}

	/**
	 * Required placeholders missing from subject+body.
	 *
	 * @param string $id      Template.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return string[]
	 */
	public static function missing( $id, $subject, $body ) {
		$def = self::definitions()[ $id ] ?? array( 'required' => array() );
		$out = array();
		foreach ( $def['required'] as $ph ) {
			if ( false === strpos( $subject . $body, '{' . $ph . '}' ) ) {
				$out[] = $ph;
			}
		}
		return $out;
	}

	/**
	 * Saves an approved edit (admin click). Reset = back to defaults.
	 *
	 * @param string $id    Template.
	 * @param array  $input subject, body, enabled, reset, source (manual|ai).
	 * @return array|\WP_Error
	 */
	public static function save( $id, array $input ) {
		if ( ! isset( self::definitions()[ $id ] ) ) {
			return new \WP_Error( 'dev3bk_not_found', __( 'Unknown template.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
		}
		$all = self::stored();
		$row = $all[ $id ] ?? array();
		if ( ! empty( $input['reset'] ) ) {
			$row = array_intersect_key( $row, array( 'enabled' => 1 ) );
		}
		if ( isset( $input['subject'] ) || isset( $input['body'] ) ) {
			$subject = mb_substr( sanitize_text_field( self::strip_unknown( (string) ( $input['subject'] ?? self::get( $id )['subject'] ) ) ), 0, 200 );
			$body    = mb_substr( sanitize_textarea_field( self::strip_unknown( (string) ( $input['body'] ?? self::get( $id )['body'] ) ) ), 0, 5000 );
			if ( '' === trim( $subject ) || '' === trim( $body ) ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'Subject and message cannot be empty.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$missing = self::missing( $id, $subject, $body );
			if ( $missing ) {
				/* translators: %s: placeholder list. */
				return new \WP_Error( 'dev3bk_missing_placeholder', sprintf( __( 'Keep these placeholders in the message: %s', 'dev3-ai-booking-appointments' ), '{' . implode( '}, {', $missing ) . '}' ), array( 'status' => 400 ) );
			}
			$row['subject']     = $subject;
			$row['body']        = $body;
			$row['source']      = 'ai' === ( $input['source'] ?? '' ) ? 'ai' : 'manual';
			$row['approved_at'] = gmdate( 'Y-m-d H:i:s' );
			$row['approved_by'] = get_current_user_id();
		}
		if ( isset( $input['enabled'] ) ) {
			$row['enabled'] = (bool) $input['enabled'];
		}
		$all[ $id ] = $row;
		update_option( self::OPTION, $all, false );
		return self::get( $id );
	}

	/**
	 * Replaces {placeholders}.
	 *
	 * @param string $text Text.
	 * @param array  $vars Vars.
	 * @return string
	 */
	public static function fill( $text, array $vars ) {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static fn( $m ) => array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : '',
			(string) $text
		);
	}
}
