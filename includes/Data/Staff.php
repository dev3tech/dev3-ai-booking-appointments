<?php
/**
 * Staff repository (+ the staff ↔ service map).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Staff members.
 */
class Staff extends Repo {

	const TABLE = 'staff';

	const FIELDS = array(
		'id'                 => 'int',
		'user_id'            => 'int',
		'name'               => 'string',
		'email'              => 'string',
		'phone'              => 'string',
		'bio'                => 'string',
		'color'              => 'string',
		'timezone'           => 'string',
		'use_business_hours' => 'bool',
		'hours'              => 'json',
		'breaks'             => 'json',
		'feed_token'         => 'string',
		'status'             => 'string',
		'sort'               => 'int',
		'created_at'         => 'string',
		'updated_at'         => 'string',
	);

	/**
	 * Cached map.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * All staff (not archived).
	 *
	 * @param bool $active_only Active only.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		global $wpdb;
		$where = $active_only ? $wpdb->prepare( 'WHERE status = %s', 'active' ) : $wpdb->prepare( 'WHERE status <> %s', 'archived' );
		$rows  = self::rows( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY sort ASC, name ASC" );
		$map   = self::service_map();
		foreach ( $rows as &$row ) {
			$row['services'] = array_values( array_map( 'intval', $map['by_staff'][ $row['id'] ] ?? array() ) );
		}
		return $rows;
	}

	/**
	 * Staff with service ids.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return null;
		}
		$map             = self::service_map();
		$row['services'] = array_values( array_map( 'intval', $map['by_staff'][ $row['id'] ] ?? array() ) );
		return $row;
	}

	/**
	 * Staff timezone (own, else business).
	 *
	 * @param array $staff Staff row.
	 * @return \DateTimeZone
	 */
	public static function tz( array $staff ) {
		$tz = Settings::sanitize_tz( $staff['timezone'] ?? '' );
		return '' !== $tz ? new \DateTimeZone( $tz ) : Settings::timezone();
	}

	/**
	 * Effective weekly hours and breaks.
	 *
	 * @param array $staff Staff row.
	 * @return array{0:array,1:array}
	 */
	public static function week( array $staff ) {
		if ( ! empty( $staff['use_business_hours'] ) ) {
			return array( Settings::sanitize_week( Settings::get( 'business_hours', array() ) ), Settings::sanitize_week( Settings::get( 'business_breaks', array() ) ) );
		}
		return array( Settings::sanitize_week( $staff['hours'] ), Settings::sanitize_week( $staff['breaks'] ) );
	}

	/**
	 * Staff ↔ service map.
	 *
	 * @return array{by_staff:array,by_service:array}
	 */
	public static function service_map() {
		if ( null === self::$map ) {
			global $wpdb;
			$rows      = $wpdb->get_results( 'SELECT staff_id, service_id FROM ' . Tables::name( 'staff_services' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			self::$map = array(
				'by_staff'   => array(),
				'by_service' => array(),
			);
			foreach ( (array) $rows as $r ) {
				self::$map['by_staff'][ (int) $r['staff_id'] ][]     = (int) $r['service_id'];
				self::$map['by_service'][ (int) $r['service_id'] ][] = (int) $r['staff_id'];
			}
		}
		return self::$map;
	}

	/**
	 * Active staff ids who perform a service.
	 *
	 * @param int $service_id Service.
	 * @return int[]
	 */
	public static function for_service( $service_id ) {
		$map = self::service_map();
		$out = array();
		foreach ( $map['by_service'][ (int) $service_id ] ?? array() as $sid ) {
			$s = self::get( $sid );
			if ( $s && 'active' === $s['status'] ) {
				$out[] = $s;
			}
		}
		usort( $out, static fn( $a, $b ) => $a['sort'] !== $b['sort'] ? $a['sort'] <=> $b['sort'] : strcmp( $a['name'], $b['name'] ) );
		return array_map( static fn( $s ) => (int) $s['id'], $out );
	}

	/**
	 * Does staff perform service?
	 *
	 * @param int $staff_id   Staff.
	 * @param int $service_id Service.
	 * @return bool
	 */
	public static function performs( $staff_id, $service_id ) {
		return in_array( (int) $staff_id, self::for_service( $service_id ), true );
	}

	/**
	 * Replaces the staff of a service.
	 *
	 * @param int   $service_id Service.
	 * @param int[] $staff_ids  Staff.
	 */
	public static function set_service_staff( $service_id, array $staff_ids ) {
		global $wpdb;
		$table = Tables::name( 'staff_services' );
		$wpdb->delete( $table, array( 'service_id' => (int) $service_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_unique( array_filter( $staff_ids ) ) as $sid ) {
			if ( self::get( $sid ) ) {
				$wpdb->insert(
					$table,
					array(
						'staff_id'   => (int) $sid,
						'service_id' => (int) $service_id,
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		self::$map = null;
	}

	/**
	 * Replaces the services of a staff member.
	 *
	 * @param int   $staff_id    Staff.
	 * @param int[] $service_ids Services.
	 */
	public static function set_staff_services( $staff_id, array $service_ids ) {
		global $wpdb;
		$table = Tables::name( 'staff_services' );
		$wpdb->delete( $table, array( 'staff_id' => (int) $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_unique( array_filter( $service_ids ) ) as $sid ) {
			if ( Services::get( $sid ) ) {
				$wpdb->insert(
					$table,
					array(
						'staff_id'   => (int) $staff_id,
						'service_id' => (int) $sid,
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		self::$map = null;
	}

	/**
	 * Clears caches.
	 */
	public static function forget() {
		parent::forget();
		self::$map = null;
	}

	/**
	 * Sanitizes editor input.
	 *
	 * @param array $in Raw.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $in ) {
		$out = array();
		if ( isset( $in['name'] ) ) {
			$out['name'] = mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 190 );
			if ( '' === $out['name'] ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'Enter the staff member\'s name.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
		}
		if ( isset( $in['email'] ) ) {
			$email        = sanitize_email( (string) $in['email'] );
			$out['email'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $in['phone'] ) ) {
			$out['phone'] = mb_substr( preg_replace( '/[^0-9+()\-\s.]/', '', (string) $in['phone'] ), 0, 40 );
		}
		if ( isset( $in['bio'] ) ) {
			$out['bio'] = mb_substr( sanitize_textarea_field( (string) $in['bio'] ), 0, 1000 );
		}
		if ( isset( $in['color'] ) ) {
			$out['color'] = sanitize_hex_color( (string) $in['color'] ) ? sanitize_hex_color( (string) $in['color'] ) : '#0ea5e9';
		}
		if ( isset( $in['timezone'] ) ) {
			$out['timezone'] = Settings::sanitize_tz( $in['timezone'] );
		}
		if ( isset( $in['use_business_hours'] ) ) {
			$out['use_business_hours'] = (bool) $in['use_business_hours'];
		}
		if ( isset( $in['hours'] ) ) {
			$out['hours'] = Settings::sanitize_week( $in['hours'] );
		}
		if ( isset( $in['breaks'] ) ) {
			$out['breaks'] = Settings::sanitize_week( $in['breaks'] );
		}
		if ( isset( $in['user_id'] ) ) {
			$out['user_id'] = max( 0, (int) $in['user_id'] );
		}
		if ( isset( $in['status'] ) && in_array( $in['status'], array( 'active', 'inactive' ), true ) ) {
			$out['status'] = $in['status'];
		}
		if ( isset( $in['sort'] ) && is_numeric( $in['sort'] ) ) {
			$out['sort'] = (int) $in['sort'];
		}
		return $out;
	}

	/**
	 * Creates or updates a staff member and their services.
	 *
	 * @param array $in Raw.
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
				return new \WP_Error( 'dev3bk_not_found', __( 'Staff member not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
			}
			self::update( $id, $data );
		} else {
			if ( empty( $data['name'] ) ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'Enter the staff member\'s name.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$data += array(
				'bio'        => '',
				'hours'      => Settings::default_hours(),
				'breaks'     => array(),
				'feed_token' => wp_generate_password( 32, false, false ),
			);
			$id    = self::insert( $data );
		}
		if ( isset( $in['services'] ) && is_array( $in['services'] ) ) {
			self::set_staff_services( $id, array_map( 'intval', $in['services'] ) );
		}
		return self::find( $id );
	}

	/**
	 * Deletes (or archives when booked) a staff member.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function remove( $id ) {
		global $wpdb;
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Bookings::table() . ' WHERE staff_id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		self::set_staff_services( $id, array() );
		if ( $used ) {
			return self::update( $id, array( 'status' => 'archived' ) );
		}
		$wpdb->delete( Exceptions::table(), array( 'staff_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::delete( $id );
	}

	/**
	 * Public view (no email/phone/token).
	 *
	 * @param array $s Staff.
	 * @return array
	 */
	public static function public_view( array $s ) {
		return array(
			'id'       => (int) $s['id'],
			'name'     => $s['name'],
			'bio'      => $s['bio'],
			'color'    => $s['color'],
			'initials' => self::initials( $s['name'] ),
			'services' => $s['services'] ?? array(),
		);
	}

	/**
	 * Initials for avatars.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( (array) $parts, 0, 2 ) as $p ) {
			$out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return $out;
	}
}
