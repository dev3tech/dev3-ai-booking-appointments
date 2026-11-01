<?php
/**
 * Customers repository.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Customers (matched by email).
 */
class Customers extends Repo {

	const TABLE = 'customers';

	const FIELDS = array(
		'id'         => 'int',
		'user_id'    => 'int',
		'name'       => 'string',
		'email'      => 'string',
		'phone'      => 'string',
		'timezone'   => 'string',
		'notes'      => 'string',
		'created_at' => 'string',
		'updated_at' => 'string',
	);

	/**
	 * Customer by email.
	 *
	 * @param string $email Email.
	 * @return array|null
	 */
	public static function by_email( $email ) {
		global $wpdb;
		return self::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', strtolower( (string) $email ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Finds or creates a customer, filling blanks and linking the WP user.
	 *
	 * @param array $in name, email, phone, timezone, user_id.
	 * @return array|\WP_Error
	 */
	public static function upsert( array $in ) {
		$email = strtolower( sanitize_email( (string) ( $in['email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3bk_invalid_email', __( 'Enter a valid email address.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$data = array(
			'name'     => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 ),
			'phone'    => mb_substr( preg_replace( '/[^0-9+()\-\s.]/', '', (string) ( $in['phone'] ?? '' ) ), 0, 40 ),
			'timezone' => (string) ( $in['timezone'] ?? '' ),
			'user_id'  => (int) ( $in['user_id'] ?? 0 ),
		);
		$row  = self::by_email( $email );
		if ( $row ) {
			$patch = array();
			foreach ( $data as $k => $v ) {
				if ( $v && $v !== $row[ $k ] && ( 'user_id' !== $k || ! $row['user_id'] ) ) {
					$patch[ $k ] = $v;
				}
			}
			if ( $patch ) {
				self::update( $row['id'], $patch );
			}
			return self::get( $row['id'] );
		}
		$id = self::insert(
			$data + array(
				'email' => $email,
				'notes' => '',
			)
		);
		return $id ? self::get( $id ) : new \WP_Error( 'dev3bk_db', __( 'Could not save the customer.', 'dev3-ai-booking-appointments' ), array( 'status' => 500 ) );
	}

	/**
	 * Booking counts by status for a customer.
	 *
	 * @param int $customer_id Customer.
	 * @return array
	 */
	public static function stats( $customer_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, SUM(paid_amount) AS paid, MAX(starts_at) AS last FROM ' . Bookings::table() . ' WHERE customer_id = %d GROUP BY status', $customer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array(
			'total'       => 0,
			'completed'   => 0,
			'no_show'     => 0,
			'cancelled'   => 0,
			'late_cancel' => 0,
			'paid'        => 0.0,
			'last'        => '',
		);
		foreach ( (array) $rows as $r ) {
			$out['total'] += (int) $r['n'];
			if ( isset( $out[ $r['status'] ] ) && 'paid' !== $r['status'] ) {
				$out[ $r['status'] ] = (int) $r['n'];
			}
			$out['paid'] += (float) $r['paid'];
			if ( in_array( $r['status'], array( 'completed', 'confirmed', 'no_show' ), true ) && $r['last'] > $out['last'] ) {
				$out['last'] = (string) $r['last'];
			}
		}
		// Late cancellations: cancelled less than 24 h before the start.
		$out['late_cancel'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Bookings::table() . " WHERE customer_id = %d AND status = 'cancelled' AND cancelled_at IS NOT NULL AND TIMESTAMPDIFF(HOUR, cancelled_at, starts_at) < 24", $customer_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out['paid']        = round( $out['paid'], 2 );
		return $out;
	}

	/**
	 * Paged list with stats.
	 *
	 * @param array $args search, page, per_page.
	 * @return array{rows:array,total:int}
	 */
	public static function search( array $args ) {
		global $wpdb;
		$per    = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where  = '1=1';
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s)', $like, $like, $like );
		}
		$b     = Bookings::table();
		$t     = self::table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} c WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql   = $wpdb->prepare(
			"SELECT c.*, COUNT(b.id) AS bookings, SUM(b.status = 'no_show') AS no_shows, SUM(b.status = 'completed') AS completed, MAX(b.starts_at) AS last_booking, COALESCE(SUM(b.paid_amount),0) AS revenue FROM {$t} c LEFT JOIN {$b} b ON b.customer_id = c.id WHERE {$where} GROUP BY c.id ORDER BY last_booking DESC, c.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$per,
			( $page - 1 ) * $per
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$c                 = self::cast( array_intersect_key( $r, self::FIELDS ) );
			$c['bookings']     = (int) $r['bookings'];
			$c['no_shows']     = (int) $r['no_shows'];
			$c['completed']    = (int) $r['completed'];
			$c['last_booking'] = (string) $r['last_booking'];
			$c['revenue']      = round( (float) $r['revenue'], 2 );
			$out[]             = $c;
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}
}
