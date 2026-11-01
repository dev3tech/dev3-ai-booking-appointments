<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3bk_settings = get_option( 'dev3bk_settings', array() );
if ( empty( $dev3bk_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3bk_usage', 'dev3bk_core_cache', 'dev3bk_core_jobs', 'dev3bk_core_embeddings', 'dev3bk_services', 'dev3bk_staff', 'dev3bk_staff_services', 'dev3bk_exceptions', 'dev3bk_customers', 'dev3bk_bookings', 'dev3bk_events' ) as $dev3bk_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3bk_table}" );
}
foreach ( array( 'dev3bk_settings', 'dev3bk_license', 'dev3bk_db_version', 'dev3bk_templates', 'dev3bk_stripe', 'dev3bk_woo_product' ) as $dev3bk_option ) {
	delete_option( $dev3bk_option );
}
delete_transient( 'dev3bk_models' );
delete_site_transient( 'dev3bk_update_info' );
wp_unschedule_hook( 'dev3bk_run_job' );
wp_unschedule_hook( 'dev3bk_reminders' );
wp_unschedule_hook( 'dev3bk_daily' );
