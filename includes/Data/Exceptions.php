<?php
/**
 * Time off, closures/holidays (staff_id 0) and special working hours for dates.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exceptions repository.
 */
class Exceptions extends Repo {

	const TABLE = 'exceptions';

	const FIELDS = array(
		'id'         => 'int',
		'staff_id'   => 'int',
		'type'       => 'string',
		'date_from'  => 'string',
		'date_to'    => 'string',
		'start_time' => 'string',
		'end_time'   => 'string',
		'label'      => 'string',
		'yearly'     => 'bool',
		'created_at' => 'string',
	);

	/**
	 * Per-request list cache by staff id.
	 *
	 * @var array
	 */
	private static $by_staff = array();

	/**
	 * Exceptions of a staff member (0 = business closures only).
	 *
	 * @param int $staff_id Staff.
	 * @return array
	 */
	public static function for_staff( $staff_id ) {
		$staff_id = (int) $staff_id;
		if ( ! isset( self::$by_staff[ $staff_id ] ) ) {
			global $wpdb;
			self::$by_staff[ $staff_id ] = self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE staff_id = %d ORDER BY date_from ASC', $staff_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return self::$by_staff[ $staff_id ];
	}

	/**
	 * Does an exception cover a date (yearly ones repeat by month-day)?
	 *
	 * @param array  $ex   Exception.
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public static function covers( array $ex, $date ) {
		if ( $ex['yearly'] ) {
			$md   = substr( $date, 5 );
			$from = substr( $ex['date_from'], 5 );
			$to   = substr( $ex['date_to'], 5 );
			return $from <= $to ? ( $md >= $from && $md <= $to ) : ( $md >= $from || $md <= $to );
		}
		return $date >= $ex['date_from'] && $date <= $ex['date_to'];
	}

	/**
	 * Exceptions that apply to a staff-local date: business closures + own entries.
	 *
	 * @param int    $staff_id Staff.
	 * @param string $date     Y-m-d.
	 * @return array
	 */
	public static function on_date( $staff_id, $date ) {
		$out = array();
		foreach ( array_merge( self::for_staff( 0 ), $staff_id ? self::for_staff( $staff_id ) : array() ) as $ex ) {
			if ( self::covers( $ex, $date ) ) {
				$out[] = $ex;
			}
		}
		return $out;
	}

	/**
	 * Clears caches.
	 */
	public static function forget() {
		parent::forget();
		self::$by_staff = array();
	}

	/**
	 * Creates an exception from raw input.
	 *
	 * @param array $in Raw.
	 * @return array|\WP_Error
	 */
	public static function create( array $in ) {
		$type  = isset( $in['type'] ) && 'hours' === $in['type'] ? 'hours' : 'off';
		$from  = self::date( $in['date_from'] ?? '' );
		$to    = self::date( $in['date_to'] ?? '' );
		$to    = $to ? $to : $from;
		$start = Settings::time( (string) ( $in['start_time'] ?? '' ) );
		$end   = Settings::time( (string) ( $in['end_time'] ?? '' ) );
		if ( ! $from || $to < $from ) {
			return new \WP_Error( 'dev3bk_invalid', __( 'Choose a valid date range.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		if ( ( $start || $end ) && ( ! $start || ! $end || $start >= $end ) ) {
			return new \WP_Error( 'dev3bk_invalid', __( 'The start time must be before the end time.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		if ( 'hours' === $type && ! $start ) {
			return new \WP_Error( 'dev3bk_invalid', __( 'Special hours need a start and end time.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$staff_id = max( 0, (int) ( $in['staff_id'] ?? 0 ) );
		if ( $staff_id && ! Staff::get( $staff_id ) ) {
			return new \WP_Error( 'dev3bk_not_found', __( 'Staff member not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
		}
		$id = self::insert(
			array(
				'staff_id'   => $staff_id,
				'type'       => $type,
				'date_from'  => $from,
				'date_to'    => $to,
				'start_time' => (string) $start,
				'end_time'   => (string) $end,
				'label'      => mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 190 ),
				'yearly'     => ! empty( $in['yearly'] ),
			)
		);
		self::forget();
		return self::get( $id );
	}

	/**
	 * Valid Y-m-d or ''.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function date( $value ) {
		$value = (string) $value;
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}
}
