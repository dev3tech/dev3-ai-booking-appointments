<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Admin;

use Dev3\AiBooking\Core3\Core\Assets;
use Dev3\AiBooking\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3BK_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		global $wpdb;
		$pending    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Dev3\AiBooking\Data\Bookings::table() . ' WHERE status = %s AND starts_at >= %s', 'pending', gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$bubble     = $pending ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . number_format_i18n( $pending ) . '</span></span>' : '';
		$this->hook = (string) add_menu_page( DEV3BK_NAME, __( 'DEV3 AI Booking', 'dev3-ai-booking-appointments' ) . $bubble, Capabilities::manage(), DEV3BK_SLUG, array( $this, 'render' ), 'dashicons-calendar-alt', 27 );
		$subs       = array(
			''              => __( 'Dashboard', 'dev3-ai-booking-appointments' ),
			'calendar'      => __( 'Calendar', 'dev3-ai-booking-appointments' ),
			'bookings'      => __( 'Bookings', 'dev3-ai-booking-appointments' ),
			'services'      => __( 'Services', 'dev3-ai-booking-appointments' ),
			'staff'         => __( 'Staff', 'dev3-ai-booking-appointments' ),
			'customers'     => __( 'Customers', 'dev3-ai-booking-appointments' ),
			'notifications' => __( 'Notifications', 'dev3-ai-booking-appointments' ),
			'settings'      => __( 'Settings', 'dev3-ai-booking-appointments' ),
		);
		foreach ( $subs as $route => $label ) {
			add_submenu_page( DEV3BK_SLUG, $label, $label, Capabilities::manage(), '' === $route ? DEV3BK_SLUG : 'admin.php?page=' . DEV3BK_SLUG . '#/' . $route );
		}
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3bk-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3BK_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3bk-admin-root" class="dev3bk-root dev3bk-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3BK_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-booking-appointments' ) . '</a>' );
		return $links;
	}
}
