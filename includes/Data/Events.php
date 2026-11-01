<?php
/**
 * Booking timeline.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Events repository.
 */
class Events extends Repo {

	const TABLE = 'events';

	const FIELDS = array(
		'id'         => 'int',
		'booking_id' => 'int',
		'type'       => 'string',
		'actor'      => 'string',
		'user_id'    => 'int',
		'message'    => 'string',
		'meta'       => 'json',
		'created_at' => 'string',
	);

	/**
	 * Logs an event.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $type       Type.
	 * @param string $message    Message.
	 * @param string $actor      customer|admin|system|ai.
	 * @param array  $meta       Meta.
	 * @return int
	 */
	public static function log( $booking_id, $type, $message, $actor = 'system', array $meta = array() ) {
		return self::insert(
			array(
				'booking_id' => (int) $booking_id,
				'type'       => $type,
				'actor'      => $actor,
				'user_id'    => get_current_user_id(),
				'message'    => mb_substr( (string) $message, 0, 2000 ),
				'meta'       => $meta,
			)
		);
	}

	/**
	 * Timeline for a booking (newest first).
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function for_booking( $booking_id ) {
		global $wpdb;
		return self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 100', $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
