<?php
/**
 * Customer area: [dev3bk_my_bookings] / "My bookings" block / WooCommerce My Account tab.
 * Logged-in customers see their bookings; guests open one booking through the emailed link.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Account.
 */
class Account {

	/**
	 * Shortcode.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::render();
	}

	/**
	 * Markup (the app reads ?dev3bk_ref=&dev3bk_key= itself).
	 *
	 * @return string
	 */
	public static function render() {
		Widget::enqueue();
		$s = Strings::all();
		return '<div class="dev3bk-account" style="' . esc_attr( Widget::style() ) . '">'
			. '<div class="dev3bk-boot" aria-busy="true"><span class="dev3bk-spin" aria-hidden="true"></span><span>' . esc_html( $s['loading'] ) . '</span></div>'
			. '<noscript><p>' . esc_html__( 'Please enable JavaScript to manage your bookings.', 'dev3-ai-booking-appointments' ) . '</p></noscript>'
			. '</div>';
	}
}
