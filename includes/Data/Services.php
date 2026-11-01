<?php
/**
 * Services repository.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Services.
 */
class Services extends Repo {

	const TABLE = 'services';

	const FIELDS = array(
		'id'              => 'int',
		'name'            => 'string',
		'description'     => 'string',
		'category'        => 'string',
		'color'           => 'string',
		'duration'        => 'int',
		'price'           => 'float',
		'deposit_percent' => 'int',
		'capacity'        => 'int',
		'buffer_before'   => 'int',
		'buffer_after'    => 'int',
		'slot_interval'   => 'int',
		'min_notice'      => 'int',
		'max_advance'     => 'int',
		'payment_mode'    => 'string',
		'location'        => 'string',
		'status'          => 'string',
		'sort'            => 'int',
		'created_at'      => 'string',
		'updated_at'      => 'string',
	);

	const PAYMENT_MODES = array( 'off', 'optional', 'required' );

	/**
	 * All services, optionally only active ones.
	 *
	 * @param bool $active_only Active only.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		global $wpdb;
		$where = $active_only ? $wpdb->prepare( 'WHERE status = %s', 'active' ) : $wpdb->prepare( 'WHERE status <> %s', 'archived' );
		$rows  = self::rows( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY sort ASC, name ASC" );
		$map   = Staff::service_map();
		foreach ( $rows as &$row ) {
			$row['staff'] = array_values( array_map( 'intval', $map['by_service'][ $row['id'] ] ?? array() ) );
		}
		return $rows;
	}

	/**
	 * Service with staff ids.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return null;
		}
		$map          = Staff::service_map();
		$row['staff'] = array_values( array_map( 'intval', $map['by_service'][ $row['id'] ] ?? array() ) );
		return $row;
	}

	/**
	 * Effective minimum notice in minutes.
	 *
	 * @param array $service Service.
	 * @return int
	 */
	public static function min_notice( array $service ) {
		return $service['min_notice'] >= 0 ? (int) $service['min_notice'] : (int) Settings::get( 'min_notice', 120 );
	}

	/**
	 * Effective maximum advance in days.
	 *
	 * @param array $service Service.
	 * @return int
	 */
	public static function max_advance( array $service ) {
		return $service['max_advance'] > 0 ? (int) $service['max_advance'] : (int) Settings::get( 'max_advance', 60 );
	}

	/**
	 * Effective slot step in minutes.
	 *
	 * @param array $service Service.
	 * @return int
	 */
	public static function interval( array $service ) {
		return $service['slot_interval'] > 0 ? (int) $service['slot_interval'] : (int) Settings::get( 'slot_interval', 30 );
	}

	/**
	 * Amount due online for a booking of this service (deposit or full price).
	 *
	 * @param array $service Service.
	 * @param int   $seats   Seats.
	 * @return float
	 */
	public static function amount_due( array $service, $seats = 1 ) {
		$total = round( (float) $service['price'] * max( 1, (int) $seats ), 2 );
		$pct   = (int) $service['deposit_percent'];
		return $pct > 0 && $pct < 100 ? round( $total * $pct / 100, 2 ) : $total;
	}

	/**
	 * Sanitizes editor input.
	 *
	 * @param array $in Raw.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $in ) {
		$out  = array();
		$name = isset( $in['name'] ) ? mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 190 ) : null;
		if ( null !== $name ) {
			if ( '' === $name ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'Give the service a name.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$out['name'] = $name;
		}
		if ( isset( $in['description'] ) ) {
			$out['description'] = mb_substr( sanitize_textarea_field( (string) $in['description'] ), 0, 2000 );
		}
		foreach ( array(
			'category' => 100,
			'location' => 190,
		) as $key => $len ) {
			if ( isset( $in[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, $len );
			}
		}
		if ( isset( $in['color'] ) ) {
			$out['color'] = sanitize_hex_color( (string) $in['color'] ) ? sanitize_hex_color( (string) $in['color'] ) : '#4f46e5';
		}
		$ranges = array(
			'duration'        => array( 5, 720 ),
			'deposit_percent' => array( 0, 100 ),
			'capacity'        => array( 1, 500 ),
			'buffer_before'   => array( 0, 240 ),
			'buffer_after'    => array( 0, 240 ),
			'slot_interval'   => array( 0, 240 ),
			'min_notice'      => array( -1, 60 * 24 * 60 ),
			'max_advance'     => array( -1, 730 ),
			'sort'            => array( -1000, 1000 ),
		);
		foreach ( $ranges as $key => $r ) {
			if ( isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ) {
				$out[ $key ] = (int) min( $r[1], max( $r[0], (int) $in[ $key ] ) );
			}
		}
		if ( isset( $in['price'] ) && is_numeric( $in['price'] ) ) {
			$out['price'] = round( min( 1000000, max( 0, (float) $in['price'] ) ), 2 );
		}
		if ( isset( $in['payment_mode'] ) && in_array( $in['payment_mode'], self::PAYMENT_MODES, true ) ) {
			$out['payment_mode'] = $in['payment_mode'];
		}
		if ( isset( $in['status'] ) && in_array( $in['status'], array( 'active', 'hidden' ), true ) ) {
			$out['status'] = $in['status'];
		}
		return $out;
	}

	/**
	 * Creates or updates a service and its staff.
	 *
	 * @param array $in Raw input.
	 * @param int   $id Id (0 = new).
	 * @return array|\WP_Error
	 */
	public static function save( array $in, $id = 0 ) {
		$data = self::sanitize( $in );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $id ) {
			if ( ! self::get( $id ) ) {
				return new \WP_Error( 'dev3bk_not_found', __( 'Service not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
			}
			self::update( $id, $data );
		} else {
			if ( empty( $data['name'] ) ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'Give the service a name.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$id = self::insert( $data + array( 'description' => '' ) );
		}
		if ( isset( $in['staff'] ) && is_array( $in['staff'] ) ) {
			Staff::set_service_staff( $id, array_map( 'intval', $in['staff'] ) );
		}
		return self::find( $id );
	}

	/**
	 * Deletes a service; archives it instead when bookings reference it.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function remove( $id ) {
		global $wpdb;
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Bookings::table() . ' WHERE service_id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		Staff::set_service_staff( $id, array() );
		if ( $used ) {
			return self::update( $id, array( 'status' => 'archived' ) );
		}
		return self::delete( $id );
	}
}
