<?php
/**
 * Payment method resolution, the post-booking "next step" and refunds.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Payments;

use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Payments facade.
 */
class Payments {

	/**
	 * Is WooCommerce payment usable?
	 *
	 * @return bool
	 */
	public static function woo_ready() {
		return (bool) Settings::get( 'woo_enabled' ) && class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Methods available for a service, first = default.
	 *
	 * @param array $service Service.
	 * @return string[] none|later|stripe|woocommerce
	 */
	public static function methods( array $service ) {
		if ( (float) $service['price'] <= 0 || 'off' === $service['payment_mode'] ) {
			return array( (float) $service['price'] > 0 ? 'later' : 'none' );
		}
		$online = array();
		if ( Settings::get( 'stripe_enabled' ) && Stripe::ready() ) {
			$online[] = 'stripe';
		}
		if ( self::woo_ready() ) {
			$online[] = 'woocommerce';
		}
		$later = Settings::get( 'pay_later', true ) && 'required' !== $service['payment_mode'];
		if ( ! $online ) {
			return array( 'later' ); // Misconfigured "required": never block bookings; the dashboard warns.
		}
		return $later ? array_merge( $online, array( 'later' ) ) : $online;
	}

	/**
	 * Labels for the widget.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'stripe'      => __( 'Pay now by card', 'dev3-ai-booking-appointments' ),
			'woocommerce' => __( 'Pay now at checkout', 'dev3-ai-booking-appointments' ),
			'later'       => __( 'Pay at the appointment', 'dev3-ai-booking-appointments' ),
			'none'        => __( 'No payment needed', 'dev3-ai-booking-appointments' ),
		);
	}

	/**
	 * What the widget does after the booking is created.
	 *
	 * @param array $b Booking.
	 * @return array|\WP_Error { type: done|redirect, url }
	 */
	public static function next_step( array $b ) {
		if ( 'pending_payment' !== $b['status'] ) {
			return array(
				'type' => 'done',
				'url'  => '',
			);
		}
		if ( 'stripe' === $b['payment_method'] ) {
			$url = Stripe::create_session( $b );
		} elseif ( 'woocommerce' === $b['payment_method'] ) {
			$url = Woo::add_to_cart( $b );
		} else {
			$url = '';
		}
		if ( is_wp_error( $url ) || '' === $url ) {
			// Release the slot when payment cannot start.
			Bookings::update( $b['id'], array( 'status' => 'expired' ) );
			Events::log( $b['id'], 'payment', is_wp_error( $url ) ? $url->get_error_message() : __( 'Payment could not start.', 'dev3-ai-booking-appointments' ), 'system', array( 'error' => true ) );
			return is_wp_error( $url ) ? $url : new \WP_Error( 'dev3bk_payment_failed', __( 'Payment could not start. Please try again or choose another payment option.', 'dev3-ai-booking-appointments' ), array( 'status' => 502 ) );
		}
		return array(
			'type' => 'redirect',
			'url'  => $url,
		);
	}

	/**
	 * Refunds a paid booking (Stripe automatically; WooCommerce through a Woo refund).
	 *
	 * @param array $b      Booking.
	 * @param float $amount Amount.
	 * @return true|\WP_Error
	 */
	public static function refund( array $b, $amount ) {
		$amount = round( min( (float) $amount, (float) $b['paid_amount'] ), 2 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'dev3bk_nothing_to_refund', __( 'Nothing to refund.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		if ( 'stripe' === $b['payment_method'] ) {
			$result = Stripe::refund( $b, $amount );
		} elseif ( 'woocommerce' === $b['payment_method'] ) {
			$result = Woo::refund( $b, $amount );
		} else {
			$result = true; // Paid on site: recorded only, the money is returned in person.
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Bookings::update(
			$b['id'],
			array(
				'payment_status' => 'refunded',
				'paid_amount'    => round( (float) $b['paid_amount'] - $amount, 2 ),
			)
		);
		/* translators: %s: amount. */
		Events::log( $b['id'], 'refund', sprintf( __( 'Refunded %s.', 'dev3-ai-booking-appointments' ), Settings::money( $amount, $b['currency'] ) ), 'system', array( 'method' => $b['payment_method'] ) );
		return true;
	}

	/**
	 * Return URL after paying (manage page of the booking).
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function return_url( array $b ) {
		return add_query_arg( 'dev3bk_paid', '1', Presenter::manage_url( $b ) );
	}
}
