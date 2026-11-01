<?php
/**
 * Loads WooCommerce (optional payments integration) before the plugin and installs its tables.
 *
 * @package Dev3\AiBooking
 */

tests_add_filter(
	'dev3bk_test_dependencies',
	static function () {
		return array( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php' );
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer' ) ) {
				wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
			}
			global $wpdb;
			foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $dev3bk_table ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $dev3bk_table ) ) ) {
					$wpdb->query( "DELETE FROM {$wpdb->prefix}{$dev3bk_table}" ); // phpcs:ignore
				}
			}
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_roles();
		}
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'studio@example.org';
	}
);
