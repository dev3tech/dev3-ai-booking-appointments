<?php
/**
 * Creates the booking and account pages (activation, or "Create pages" in Settings).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Frontend;

use Dev3\AiBooking\Core3\Core\Settings_Store;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pages.
 */
class Pages {

	/**
	 * Ensures both pages exist.
	 *
	 * @param bool $force Recreate missing/trashed pages even when ids are set.
	 */
	public static function ensure( $force = false ) {
		$patch = array();
		foreach (
			array(
				'booking_page' => array( __( 'Book an appointment', 'dev3-ai-booking-appointments' ), '<!-- wp:dev3bk/booking-form /-->' ),
				'account_page' => array( __( 'My bookings', 'dev3-ai-booking-appointments' ), '<!-- wp:dev3bk/my-bookings /-->' ),
			) as $key => $page
		) {
			$id   = (int) Settings::get( $key, 0 );
			$post = $id ? get_post( $id ) : null;
			if ( $post && 'trash' !== $post->post_status && ! ( $force && 'publish' !== $post->post_status ) ) {
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
			if ( $new && ! is_wp_error( $new ) ) {
				$patch[ $key ] = (int) $new;
			}
		}
		if ( $patch ) {
			Settings_Store::update( $patch );
		}
	}
}
