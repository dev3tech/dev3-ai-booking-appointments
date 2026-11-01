<?php
/**
 * Bookings repository. All datetimes are UTC "Y-m-d H:i:s".
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings.
 */
class Bookings extends Repo {

	const TABLE = 'bookings';

	const FIELDS = array(
		'id'             => 'int',
		'ref'            => 'string',
		'service_id'     => 'int',
		'staff_id'       => 'int',
		'customer_id'    => 'int',
		'starts_at'      => 'string',
		'ends_at'        => 'string',
		'buffer_before'  => 'int',
		'buffer_after'   => 'int',
		'seats'          => 'int',
		'status'         => 'string',
		'payment_method' => 'string',
		'payment_status' => 'string',
		'amount'         => 'float',
		'amount_due'     => 'float',
		'paid_amount'    => 'float',
		'currency'       => 'string',
		'customer_tz'    => 'string',
		'notes'          => 'string',
		'access_key'     => 'string',
		'source'         => 'string',
		'stripe_session' => 'string',
		'stripe_intent'  => 'string',
		'wc_order_id'    => 'int',
		'reschedules'    => 'int',
		'sequence'       => 'int',
		'reminders'      => 'json',
		'risk'           => 'json',
		'cancel_reason'  => 'string',
		'created_at'     => 'string',
		'updated_at'     => 'string',
		'cancelled_at'   => 'string',
	);

	const STATUSES = array( 'pending_payment', 'pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'expired' );

	/**
	 * Statuses that occupy a slot.
	 */
	const BLOCKING = array( 'pending_payment', 'pending', 'confirmed', 'completed', 'no_show' );

	/**
	 * Translated status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending_payment' => __( 'Awaiting payment', 'dev3-ai-booking-appointments' ),
			'pending'         => __( 'Pending approval', 'dev3-ai-booking-appointments' ),
			'confirmed'       => __( 'Confirmed', 'dev3-ai-booking-appointments' ),
			'completed'       => __( 'Completed', 'dev3-ai-booking-appointments' ),
			'cancelled'       => __( 'Cancelled', 'dev3-ai-booking-appointments' ),
			'no_show'         => __( 'No-show', 'dev3-ai-booking-appointments' ),
			'expired'         => __( 'Expired', 'dev3-ai-booking-appointments' ),
		);
	}

	/**
	 * Random human-friendly reference.
	 *
	 * @return string
	 */
	public static function new_ref() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$ref = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$ref .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
		} while ( self::by_ref( $ref ) );
		return $ref;
	}

	/**
	 * Booking by reference.
	 *
	 * @param string $ref Ref.
	 * @return array|null
	 */
	public static function by_ref( $ref ) {
		global $wpdb;
		$ref = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $ref ) );
		if ( '' === $ref ) {
			return null;
		}
		return self::cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ref = %s', $ref ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fresh row (bypasses the request cache), used inside locks.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function fresh( $id ) {
		self::forget();
		return self::get( $id );
	}

	/**
	 * Is a pending_payment booking's hold expired?
	 *
	 * @param array $b   Booking.
	 * @param int   $now Unix time.
	 * @return bool
	 */
	public static function hold_expired( array $b, $now ) {
		if ( 'pending_payment' !== $b['status'] ) {
			return false;
		}
		$hold = (int) Settings::get( 'hold_minutes', 15 );
		// WooCommerce checkout keeps the hold for an hour (customers fill the checkout form).
		if ( 'woocommerce' === $b['payment_method'] ) {
			$hold = max( $hold, 60 );
		} elseif ( 'stripe' === $b['payment_method'] ) {
			$hold = max( $hold, 35 ); // Stripe Checkout sessions live at least 30 minutes.
		}
		return strtotime( $b['created_at'] . ' UTC' ) + $hold * MINUTE_IN_SECONDS < $now;
	}

	/**
	 * Blocking bookings of the given staff that may overlap [from, to) (UTC unix), buffers included.
	 *
	 * @param int[] $staff_ids  Staff.
	 * @param int   $from       From (unix).
	 * @param int   $to         To (unix).
	 * @param int   $now        Now (unix) for hold expiry.
	 * @param int   $exclude_id Booking to ignore (reschedule).
	 * @return array staff_id => list of bookings.
	 */
	public static function busy( array $staff_ids, $from, $to, $now, $exclude_id = 0 ) {
		global $wpdb;
		$out = array();
		$ids = array_values( array_filter( array_map( 'intval', $staff_ids ) ) );
		if ( ! $ids ) {
			return $out;
		}
		// Buffers are at most 4 h each; widen the window so neighbours' buffers are seen.
		$lo     = gmdate( 'Y-m-d H:i:s', $from - 5 * HOUR_IN_SECONDS );
		$hi     = gmdate( 'Y-m-d H:i:s', $to + 5 * HOUR_IN_SECONDS );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$status = implode( ',', array_fill( 0, count( self::BLOCKING ), '%s' ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id IN ({$in}) AND status IN ({$status}) AND starts_at < %s AND ends_at > %s AND id <> %d", array_merge( $ids, self::BLOCKING, array( $hi, $lo, (int) $exclude_id ) ) );
		foreach ( self::rows( $sql ) as $b ) {
			if ( self::hold_expired( $b, $now ) ) {
				continue;
			}
			$out[ $b['staff_id'] ][] = $b;
		}
		return $out;
	}

	/**
	 * Paged, filtered list.
	 *
	 * @param array $args status, staff, service, customer, from, to (UTC), search, payment, page, per_page, order.
	 * @return array{rows:array,total:int,counts:array}
	 */
	public static function search( array $args ) {
		global $wpdb;
		$t     = self::table();
		$c     = Customers::table();
		$where = array( '1=1' );
		foreach ( array(
			'staff'    => 'staff_id',
			'service'  => 'service_id',
			'customer' => 'customer_id',
		) as $arg => $col ) {
			if ( ! empty( $args[ $arg ] ) ) {
				$where[] = $wpdb->prepare( "b.{$col} = %d", (int) $args[ $arg ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = $wpdb->prepare( 'b.starts_at >= %s', $args['from'] );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = $wpdb->prepare( 'b.starts_at < %s', $args['to'] );
		}
		if ( ! empty( $args['payment'] ) ) {
			$where[] = $wpdb->prepare( 'b.payment_status = %s', $args['payment'] );
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(b.ref LIKE %s OR c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s)', $like, $like, $like, $like );
		}
		$base   = implode( ' AND ', $where );
		$counts = array();
		$rows   = $wpdb->get_results( "SELECT b.status, COUNT(*) AS n FROM {$t} b LEFT JOIN {$c} c ON c.id = b.customer_id WHERE {$base} GROUP BY b.status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		$counts['all'] = array_sum( $counts );
		$status        = (string) ( $args['status'] ?? '' );
		if ( 'upcoming' === $status ) {
			$base .= $wpdb->prepare( " AND b.status IN ('confirmed','pending','pending_payment') AND b.starts_at >= %s", self::now() );
		} elseif ( in_array( $status, self::STATUSES, true ) ) {
			$base .= $wpdb->prepare( ' AND b.status = %s', $status );
		}
		$per   = min( 500, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$order = 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} b LEFT JOIN {$c} c ON c.id = b.customer_id WHERE {$base}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql   = $wpdb->prepare( "SELECT b.* FROM {$t} b LEFT JOIN {$c} c ON c.id = b.customer_id WHERE {$base} ORDER BY b.starts_at {$order}, b.id {$order} LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'rows'   => self::rows( $sql ),
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Bookings starting in [from, to) (UTC strings), any status unless filtered.
	 *
	 * @param string $from     From.
	 * @param string $to       To.
	 * @param array  $statuses Statuses (empty = all).
	 * @return array
	 */
	public static function between( $from, $to, array $statuses = array() ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE starts_at >= %s AND starts_at < %s', $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $statuses ) {
			$sql .= $wpdb->prepare( ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		return self::rows( $sql . ' ORDER BY starts_at ASC' );
	}

	/**
	 * Constant-time key check.
	 *
	 * @param array  $b   Booking.
	 * @param string $key Key.
	 * @return bool
	 */
	public static function key_ok( array $b, $key ) {
		return '' !== (string) $key && '' !== $b['access_key'] && hash_equals( $b['access_key'], (string) $key );
	}
}
