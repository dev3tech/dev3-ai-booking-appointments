<?php
/**
 * Customer cancellation / reschedule / refund policy.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Booking;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Policy checks (customer actions only; admins can always act).
 */
class Policy {

	/**
	 * Can the customer cancel?
	 *
	 * @param array $b   Booking.
	 * @param int   $now Unix.
	 * @return true|\WP_Error
	 */
	public static function can_cancel( array $b, $now = 0 ) {
		$now = $now ? $now : Clock::now();
		if ( ! Settings::get( 'allow_cancel', true ) ) {
			return self::deny( 'dev3bk_policy_cancel_off', __( 'Online cancellation is not available. Please contact us.', 'dev3-ai-booking-appointments' ) );
		}
		if ( ! in_array( $b['status'], array( 'pending_payment', 'pending', 'confirmed' ), true ) ) {
			return self::deny( 'dev3bk_policy_status', __( 'This booking can no longer be changed.', 'dev3-ai-booking-appointments' ) );
		}
		$cutoff = (int) Settings::get( 'cancel_cutoff_hours', 24 ) * HOUR_IN_SECONDS;
		if ( Clock::ts( $b['starts_at'] ) - $now < $cutoff ) {
			/* translators: %d: hours. */
			return self::deny( 'dev3bk_policy_cutoff', sprintf( _n( 'Bookings can be cancelled online up to %d hour before the start. Please contact us.', 'Bookings can be cancelled online up to %d hours before the start. Please contact us.', (int) Settings::get( 'cancel_cutoff_hours', 24 ), 'dev3-ai-booking-appointments' ), (int) Settings::get( 'cancel_cutoff_hours', 24 ) ) );
		}
		return self::filter( true, 'cancel', $b );
	}

	/**
	 * Can the customer reschedule?
	 *
	 * @param array $b   Booking.
	 * @param int   $now Unix.
	 * @return true|\WP_Error
	 */
	public static function can_reschedule( array $b, $now = 0 ) {
		$now = $now ? $now : Clock::now();
		if ( ! Settings::get( 'allow_reschedule', true ) ) {
			return self::deny( 'dev3bk_policy_reschedule_off', __( 'Online rescheduling is not available. Please contact us.', 'dev3-ai-booking-appointments' ) );
		}
		if ( ! in_array( $b['status'], array( 'pending', 'confirmed' ), true ) ) {
			return self::deny( 'dev3bk_policy_status', __( 'This booking can no longer be changed.', 'dev3-ai-booking-appointments' ) );
		}
		$max = (int) Settings::get( 'max_reschedules', 2 );
		if ( (int) $b['reschedules'] >= $max ) {
			return self::deny( 'dev3bk_policy_max', __( 'This booking has already been rescheduled the maximum number of times. Please contact us.', 'dev3-ai-booking-appointments' ) );
		}
		$hours = (int) Settings::get( 'reschedule_cutoff_hours', 24 );
		if ( Clock::ts( $b['starts_at'] ) - $now < $hours * HOUR_IN_SECONDS ) {
			/* translators: %d: hours. */
			return self::deny( 'dev3bk_policy_cutoff', sprintf( _n( 'Bookings can be rescheduled online up to %d hour before the start. Please contact us.', 'Bookings can be rescheduled online up to %d hours before the start. Please contact us.', $hours, 'dev3-ai-booking-appointments' ), $hours ) );
		}
		return self::filter( true, 'reschedule', $b );
	}

	/**
	 * Refund owed when the customer cancels now.
	 *
	 * @param array $b   Booking.
	 * @param int   $now Unix.
	 * @return float
	 */
	public static function refund_amount( array $b, $now = 0 ) {
		$now = $now ? $now : Clock::now();
		if ( 'paid' !== $b['payment_status'] || $b['paid_amount'] <= 0 ) {
			return 0.0;
		}
		$window = (int) Settings::get( 'refund_window_hours', 24 ) * HOUR_IN_SECONDS;
		return Clock::ts( $b['starts_at'] ) - $now >= $window ? (float) $b['paid_amount'] : 0.0;
	}

	/**
	 * Plain-language policy for the customer.
	 *
	 * @return string
	 */
	public static function summary() {
		$custom = trim( (string) Settings::get( 'policy_text', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$parts = array();
		if ( Settings::get( 'allow_cancel', true ) ) {
			/* translators: %d: hours. */
			$parts[] = sprintf( __( 'Free cancellation up to %d hours before your appointment.', 'dev3-ai-booking-appointments' ), (int) Settings::get( 'cancel_cutoff_hours', 24 ) );
		}
		if ( Settings::get( 'allow_reschedule', true ) ) {
			/* translators: 1: hours, 2: count. */
			$parts[] = sprintf( __( 'Reschedule online up to %1$d hours before (up to %2$d times).', 'dev3-ai-booking-appointments' ), (int) Settings::get( 'reschedule_cutoff_hours', 24 ), (int) Settings::get( 'max_reschedules', 2 ) );
		}
		if ( Settings::get( 'stripe_enabled' ) || Settings::get( 'woo_enabled' ) ) {
			/* translators: %d: hours. */
			$parts[] = sprintf( __( 'Online payments are refunded in full when you cancel at least %d hours ahead.', 'dev3-ai-booking-appointments' ), (int) Settings::get( 'refund_window_hours', 24 ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function deny( $code, $message ) {
		return new \WP_Error( $code, $message, array( 'status' => 403 ) );
	}

	/**
	 * Filter hook.
	 *
	 * @param true|\WP_Error $result Result.
	 * @param string         $action cancel|reschedule.
	 * @param array          $b      Booking.
	 * @return true|\WP_Error
	 */
	private static function filter( $result, $action, array $b ) {
		/**
		 * Allows/denies a customer action.
		 *
		 * @param true|\WP_Error $result Result.
		 * @param string         $action Action.
		 * @param array          $b      Booking.
		 */
		return apply_filters( 'dev3bk_policy_allows', $result, $action, $b );
	}
}
