<?php
/**
 * Plugin tables (added to the core dbDelta run through dev3bk_schema_sql).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	const NAMES = array( 'services', 'staff', 'staff_services', 'exceptions', 'customers', 'bookings', 'events' );

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3bk_' . $name;
	}

	/**
	 * Adds CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$t     = array();
		$names = self::NAMES;
		foreach ( $names as $n ) {
			$t[ $n ] = self::name( $n );
		}
		$sql[] = "CREATE TABLE {$t['services']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			description text NOT NULL,
			category varchar(100) NOT NULL DEFAULT '',
			color varchar(7) NOT NULL DEFAULT '#4f46e5',
			duration smallint(5) unsigned NOT NULL DEFAULT 60,
			price decimal(12,2) NOT NULL DEFAULT 0,
			deposit_percent tinyint(3) unsigned NOT NULL DEFAULT 0,
			capacity smallint(5) unsigned NOT NULL DEFAULT 1,
			buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
			buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
			slot_interval smallint(5) unsigned NOT NULL DEFAULT 0,
			min_notice int(10) NOT NULL DEFAULT -1,
			max_advance int(10) NOT NULL DEFAULT -1,
			payment_mode varchar(12) NOT NULL DEFAULT 'optional',
			location varchar(190) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			sort int(10) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_sort (status,sort)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['staff']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			bio text NOT NULL,
			color varchar(7) NOT NULL DEFAULT '#0ea5e9',
			timezone varchar(64) NOT NULL DEFAULT '',
			use_business_hours tinyint(1) NOT NULL DEFAULT 1,
			hours text NOT NULL,
			breaks text NOT NULL,
			feed_token char(32) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			sort int(10) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_sort (status,sort),
			KEY user_id (user_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['staff_services']} (
			staff_id bigint(20) unsigned NOT NULL,
			service_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (staff_id,service_id),
			KEY service_id (service_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['exceptions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(8) NOT NULL DEFAULT 'off',
			date_from date NOT NULL,
			date_to date NOT NULL,
			start_time varchar(5) NOT NULL DEFAULT '',
			end_time varchar(5) NOT NULL DEFAULT '',
			label varchar(190) NOT NULL DEFAULT '',
			yearly tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY staff_dates (staff_id,date_from,date_to)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['customers']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			timezone varchar(64) NOT NULL DEFAULT '',
			notes text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY user_id (user_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['bookings']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ref varchar(16) NOT NULL DEFAULT '',
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			starts_at datetime NOT NULL,
			ends_at datetime NOT NULL,
			buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
			buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
			seats smallint(5) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			payment_method varchar(20) NOT NULL DEFAULT 'none',
			payment_status varchar(20) NOT NULL DEFAULT 'none',
			amount decimal(12,2) NOT NULL DEFAULT 0,
			amount_due decimal(12,2) NOT NULL DEFAULT 0,
			paid_amount decimal(12,2) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			customer_tz varchar(64) NOT NULL DEFAULT '',
			notes text NOT NULL,
			access_key char(32) NOT NULL DEFAULT '',
			source varchar(12) NOT NULL DEFAULT 'widget',
			stripe_session varchar(255) NOT NULL DEFAULT '',
			stripe_intent varchar(255) NOT NULL DEFAULT '',
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reschedules smallint(5) unsigned NOT NULL DEFAULT 0,
			sequence smallint(5) unsigned NOT NULL DEFAULT 0,
			reminders varchar(255) NOT NULL DEFAULT '',
			risk text NOT NULL,
			cancel_reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			cancelled_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ref (ref),
			KEY staff_time (staff_id,starts_at),
			KEY starts (starts_at),
			KEY customer (customer_id),
			KEY status (status),
			KEY wc_order (wc_order_id)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t['events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT '',
			actor varchar(12) NOT NULL DEFAULT 'system',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			meta text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY booking (booking_id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::NAMES as $n ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::name( $n ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
