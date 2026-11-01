<?php
/**
 * Plugin Name:       DEV3 AI Booking & Appointments for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-booking-appointments
 * Description:       Appointment booking with services, staff, availability, payments, reminders and DEV3 AI natural-language booking.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:  10.3
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-booking-appointments
 * Domain Path:       /languages
 *
 * @package Dev3\AiBooking
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3BK_VERSION', '1.0.0' );
define( 'DEV3BK_FILE', __FILE__ );
define( 'DEV3BK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3BK_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3BK_SLUG', 'dev3-ai-booking-appointments' );
define( 'DEV3BK_NAME', 'DEV3 AI Booking & Appointments for WordPress' );
define( 'DEV3BK_MIN_PHP', '7.4' );
define( 'DEV3BK_MIN_WP', '6.0' );
define( 'DEV3BK_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3BK_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiBooking\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3BK_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiBooking\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiBooking\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiBooking\Plugin', 'instance' ) );

// WooCommerce (optional, used for checkout payments): HPOS + Cart/Checkout blocks compatible.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
