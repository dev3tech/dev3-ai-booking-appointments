<?php
/**
 * Booking lifecycle: create (locked + re-checked), reschedule, cancel, status changes, payments.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Booking;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Notify\Mailer;
use Dev3\AiBooking\Payments\Payments;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manager.
 */
class Manager {

	/**
	 * Takes a MySQL named lock for a staff member's calendar.
	 *
	 * @param int $staff_id Staff.
	 * @return bool
	 */
	public static function lock( $staff_id ) {
		global $wpdb;
		$name = $wpdb->prefix . 'dev3bk_staff_' . (int) $staff_id;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Releases a staff lock.
	 *
	 * @param int $staff_id Staff.
	 */
	public static function unlock( $staff_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'dev3bk_staff_' . (int) $staff_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Orders candidate staff for "any staff": fewest bookings that business-local day, then sort order.
	 *
	 * @param array $candidates Staff ids (sorted).
	 * @param int   $ts         Start.
	 * @param array $service    Service.
	 * @return int[]
	 */
	public static function assign_order( array $candidates, $ts, array $service ) {
		global $wpdb;
		if ( count( $candidates ) < 2 ) {
			return $candidates;
		}
		$tz    = Settings::timezone();
		$date  = Clock::date( $ts, $tz );
		$from  = Clock::sql( Clock::local( $date, 0, $tz ) );
		$to    = Clock::sql( Clock::local( $date, 1440, $tz ) );
		$in    = implode( ',', array_fill( 0, count( $candidates ), '%d' ) );
		$table = Bookings::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, COUNT(*) n FROM {$table} WHERE staff_id IN ({$in}) AND starts_at >= %s AND starts_at < %s AND status IN ('pending_payment','pending','confirmed','completed') GROUP BY staff_id", array_merge( $candidates, array( $from, $to ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$count = array();
		foreach ( (array) $rows as $r ) {
			$count[ (int) $r['staff_id'] ] = (int) $r['n'];
		}
		$order = array_flip( $candidates );
		usort(
			$candidates,
			static function ( $a, $b ) use ( $count, $order ) {
				$diff = ( $count[ $a ] ?? 0 ) <=> ( $count[ $b ] ?? 0 );
				return 0 !== $diff ? $diff : $order[ $a ] <=> $order[ $b ];
			}
		);
		/**
		 * Filters the order in which staff are tried for an "any staff" booking.
		 *
		 * @param int[] $candidates Staff ids.
		 * @param int   $ts         Start.
		 * @param array $service    Service.
		 */
		return array_values( apply_filters( 'dev3bk_assign_staff', $candidates, $ts, $service ) );
	}

	/**
	 * Creates a booking.
	 *
	 * @param array $in service_id, staff_id (0 any), start (unix|ISO), seats, name, email, phone, notes, tz,
	 *                  payment, source, user_id; admin only: force, status, payment_status.
	 * @return array|\WP_Error Booking row.
	 */
	public static function create( array $in ) {
		$admin   = ! empty( $in['admin'] );
		$service = Services::find( (int) ( $in['service_id'] ?? 0 ) );
		if ( ! $service || 'archived' === $service['status'] || ( ! $admin && 'active' !== $service['status'] ) ) {
			return self::error( 'dev3bk_bad_service', __( 'Choose a service.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$ts = is_numeric( $in['start'] ?? null ) ? (int) $in['start'] : Clock::parse( (string) ( $in['start'] ?? '' ) );
		if ( $ts <= 0 ) {
			return self::error( 'dev3bk_bad_time', __( 'Choose a time.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$seats = max( 1, min( (int) $service['capacity'], (int) ( $in['seats'] ?? 1 ) ) );
		$force = $admin && ! empty( $in['force'] );

		$candidates = Engine::staff_for( $service, (int) ( $in['staff_id'] ?? 0 ) );
		if ( $force && ! empty( $in['staff_id'] ) && Staff::get( (int) $in['staff_id'] ) ) {
			$candidates = array( (int) $in['staff_id'] );
		}
		if ( ! $candidates ) {
			return self::error( 'dev3bk_bad_staff', __( 'Nobody is available for this service.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$candidates = self::assign_order( $candidates, $ts, $service );

		$method = self::payment_method( $service, (string) ( $in['payment'] ?? '' ), $admin );
		if ( is_wp_error( $method ) ) {
			return $method;
		}
		$phone_mode = (string) Settings::get( 'phone_field', 'optional' );
		if ( ! $admin && 'required' === $phone_mode && '' === trim( (string) ( $in['phone'] ?? '' ) ) ) {
			return self::error( 'dev3bk_phone_required', __( 'Enter your phone number.', 'dev3-ai-booking-appointments' ), 400 );
		}
		if ( '' === trim( (string) ( $in['name'] ?? '' ) ) ) {
			return self::error( 'dev3bk_name_required', __( 'Enter your name.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$customer = Customers::upsert(
			array(
				'name'     => $in['name'] ?? '',
				'email'    => $in['email'] ?? '',
				'phone'    => 'hidden' === $phone_mode && ! $admin ? '' : ( $in['phone'] ?? '' ),
				'timezone' => Clock::zone( (string) ( $in['tz'] ?? '' ) )->getName(),
				'user_id'  => (int) ( $in['user_id'] ?? 0 ),
			)
		);
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$total = round( (float) $service['price'] * $seats, 2 );
		/**
		 * Filters the booking amount (total price).
		 *
		 * @param float $total   Total.
		 * @param array $service Service.
		 * @param array $in      Input.
		 */
		$total  = round( (float) apply_filters( 'dev3bk_booking_amount', $total, $service, $in ), 2 );
		$due    = $total > 0 ? min( $total, Services::amount_due( $service, $seats ) ) : 0.0;
		$online = in_array( $method, array( 'stripe', 'woocommerce' ), true ) && $due > 0;

		if ( $online ) {
			$status = 'pending_payment';
		} elseif ( $admin && ! empty( $in['status'] ) && in_array( $in['status'], array( 'pending', 'confirmed', 'completed' ), true ) ) {
			$status = $in['status'];
		} else {
			$status = 'pending' === Settings::get( 'default_status', 'confirmed' ) && ! $admin ? 'pending' : 'confirmed';
		}
		$pay_status = $total > 0 ? 'unpaid' : 'none';
		$paid       = 0.0;
		if ( $admin && 'paid' === ( $in['payment_status'] ?? '' ) && $total > 0 ) {
			$pay_status = 'paid';
			$paid       = $total;
		}

		$row = array(
			'ref'            => Bookings::new_ref(),
			'service_id'     => (int) $service['id'],
			'customer_id'    => (int) $customer['id'],
			'starts_at'      => Clock::sql( $ts ),
			'ends_at'        => Clock::sql( $ts + (int) $service['duration'] * MINUTE_IN_SECONDS ),
			'buffer_before'  => (int) $service['buffer_before'],
			'buffer_after'   => (int) $service['buffer_after'],
			'seats'          => $seats,
			'status'         => $status,
			'payment_method' => $total > 0 ? $method : 'none',
			'payment_status' => $pay_status,
			'amount'         => $total,
			'amount_due'     => $due,
			'paid_amount'    => $paid,
			'currency'       => Settings::currency(),
			'customer_tz'    => Clock::zone( (string) ( $in['tz'] ?? '' ) )->getName(),
			'notes'          => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1000 ),
			'access_key'     => wp_generate_password( 32, false, false ),
			'source'         => in_array( $in['source'] ?? '', array( 'widget', 'admin', 'ai', 'account' ), true ) ? $in['source'] : ( $admin ? 'admin' : 'widget' ),
			'reminders'      => array(),
			'risk'           => array(),
		);

		$id = 0;
		foreach ( $candidates as $sid ) {
			if ( ! self::lock( $sid ) ) {
				continue;
			}
			Bookings::forget();
			$free = $force || Engine::is_free(
				$service,
				$sid,
				$ts,
				array(
					'seats'        => $seats,
					'ignore_rules' => $admin,
				)
			);
			if ( $free ) {
				$id = Bookings::insert( $row + array( 'staff_id' => (int) $sid ) );
			}
			self::unlock( $sid );
			if ( $id ) {
				break;
			}
		}
		if ( ! $id ) {
			return self::error( 'dev3bk_slot_taken', __( 'Sorry, that time was just taken. Please choose another time.', 'dev3-ai-booking-appointments' ), 409 );
		}
		$booking = Bookings::fresh( $id );
		Events::log( $id, 'created', $admin ? __( 'Booking created by an admin.', 'dev3-ai-booking-appointments' ) : __( 'Booking created online.', 'dev3-ai-booking-appointments' ), $admin ? 'admin' : 'customer', array( 'source' => $row['source'] ) );
		Risk::store( $booking );
		$booking = Bookings::fresh( $id );
		/**
		 * Fires after a booking is created.
		 *
		 * @param array $booking Booking.
		 */
		do_action( 'dev3bk_booking_created', $booking );
		if ( 'pending_payment' !== $status && empty( $in['silent'] ) ) {
			Mailer::booking_created( $booking );
		}
		return $booking;
	}

	/**
	 * Resolves and validates the payment method for a service.
	 *
	 * @param array  $service Service.
	 * @param string $wanted  Requested method.
	 * @param bool   $admin   Admin booking.
	 * @return string|\WP_Error
	 */
	public static function payment_method( array $service, $wanted, $admin = false ) {
		$methods = Payments::methods( $service );
		if ( array( 'none' ) === $methods ) {
			return 'none';
		}
		if ( $admin ) {
			return in_array( $wanted, array( 'later', 'none' ), true ) || '' === $wanted ? ( 'none' === $methods[0] ? 'none' : 'later' ) : $wanted;
		}
		if ( '' === $wanted ) {
			return $methods[0];
		}
		if ( ! in_array( $wanted, $methods, true ) ) {
			return self::error( 'dev3bk_bad_payment', __( 'That payment method is not available.', 'dev3-ai-booking-appointments' ), 400 );
		}
		return $wanted;
	}

	/**
	 * Moves a booking to a new start (and optionally another staff member).
	 *
	 * @param array  $b        Booking.
	 * @param int    $ts       New start.
	 * @param int    $staff_id Staff (0 = keep).
	 * @param string $actor    customer|admin.
	 * @param bool   $force    Admin: skip availability.
	 * @return array|\WP_Error
	 */
	public static function reschedule( array $b, $ts, $staff_id = 0, $actor = 'customer', $force = false ) {
		$service = Services::get( $b['service_id'] );
		if ( ! $service ) {
			return self::error( 'dev3bk_bad_service', __( 'The service no longer exists.', 'dev3-ai-booking-appointments' ), 400 );
		}
		if ( 'customer' === $actor ) {
			$ok = Policy::can_reschedule( $b );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$force = false;
		}
		$staff_id   = $staff_id ? (int) $staff_id : (int) $b['staff_id'];
		$candidates = $force ? array( $staff_id ) : Engine::staff_for( $service, $staff_id );
		if ( ! $candidates ) {
			return self::error( 'dev3bk_bad_staff', __( 'That staff member is not available for this service.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$old = $b['starts_at'];
		$ok  = false;
		if ( self::lock( $staff_id ) ) {
			Bookings::forget();
			$ok = $force || Engine::is_free(
				$service,
				$staff_id,
				$ts,
				array(
					'seats'        => (int) $b['seats'],
					'exclude'      => (int) $b['id'],
					'ignore_rules' => 'admin' === $actor,
				)
			);
			if ( $ok ) {
				Bookings::update(
					$b['id'],
					array(
						'staff_id'    => $staff_id,
						'starts_at'   => Clock::sql( $ts ),
						'ends_at'     => Clock::sql( $ts + (int) $service['duration'] * MINUTE_IN_SECONDS ),
						'reschedules' => (int) $b['reschedules'] + ( 'customer' === $actor ? 1 : 0 ),
						'sequence'    => (int) $b['sequence'] + 1,
						'reminders'   => array(),
					)
				);
			}
			self::unlock( $staff_id );
		}
		if ( ! $ok ) {
			return self::error( 'dev3bk_slot_taken', __( 'Sorry, that time is not available. Please choose another time.', 'dev3-ai-booking-appointments' ), 409 );
		}
		$booking = Bookings::fresh( $b['id'] );
		/* translators: 1: old UTC time, 2: new UTC time. */
		Events::log( $b['id'], 'rescheduled', sprintf( __( 'Moved from %1$s to %2$s (UTC).', 'dev3-ai-booking-appointments' ), $old, $booking['starts_at'] ), $actor );
		Risk::store( $booking );
		$booking = Bookings::fresh( $b['id'] );
		/**
		 * Fires after a booking moves.
		 *
		 * @param array  $booking Booking.
		 * @param string $old     Old start (UTC).
		 */
		do_action( 'dev3bk_booking_rescheduled', $booking, $old );
		Mailer::booking_rescheduled( $booking );
		return $booking;
	}

	/**
	 * Cancels a booking; customer cancellations follow the policy and may refund automatically.
	 *
	 * @param array  $b      Booking.
	 * @param string $reason Reason.
	 * @param string $actor  customer|admin|system.
	 * @param bool   $refund Admin: refund the paid amount (Stripe).
	 * @return array|\WP_Error
	 */
	public static function cancel( array $b, $reason = '', $actor = 'customer', $refund = false ) {
		if ( 'customer' === $actor ) {
			$ok = Policy::can_cancel( $b );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		} elseif ( in_array( $b['status'], array( 'cancelled', 'expired' ), true ) ) {
			return self::error( 'dev3bk_already_cancelled', __( 'This booking is already cancelled.', 'dev3-ai-booking-appointments' ), 400 );
		}
		$amount = 'customer' === $actor ? ( Settings::get( 'auto_refund', true ) ? Policy::refund_amount( $b ) : 0.0 ) : ( $refund ? (float) $b['paid_amount'] : 0.0 );
		Bookings::update(
			$b['id'],
			array(
				'status'        => 'cancelled',
				'cancel_reason' => mb_substr( sanitize_text_field( (string) $reason ), 0, 255 ),
				'cancelled_at'  => Clock::sql( Clock::now() ),
			)
		);
		$message = '' !== trim( (string) $reason ) ? sanitize_text_field( (string) $reason ) : __( 'Booking cancelled.', 'dev3-ai-booking-appointments' );
		Events::log( $b['id'], 'cancelled', $message, $actor );
		$booking = Bookings::fresh( $b['id'] );
		if ( $amount > 0 ) {
			$refunded = Payments::refund( $booking, $amount );
			if ( is_wp_error( $refunded ) ) {
				Events::log( $b['id'], 'refund', $refunded->get_error_message(), 'system', array( 'error' => true ) );
			}
			$booking = Bookings::fresh( $b['id'] );
		}
		self::status_changed( $booking, $b['status'] );
		Mailer::booking_cancelled( $booking, $actor );
		return $booking;
	}

	/**
	 * Admin status change (confirm, complete, no-show, pending).
	 *
	 * @param array  $b      Booking.
	 * @param string $status New status.
	 * @param string $actor  Actor.
	 * @return array|\WP_Error
	 */
	public static function set_status( array $b, $status, $actor = 'admin' ) {
		if ( 'cancelled' === $status ) {
			return self::cancel( $b, '', $actor );
		}
		if ( ! in_array( $status, array( 'pending', 'confirmed', 'completed', 'no_show' ), true ) ) {
			return self::error( 'dev3bk_bad_status', __( 'Unknown status.', 'dev3-ai-booking-appointments' ), 400 );
		}
		if ( $status === $b['status'] ) {
			return $b;
		}
		Bookings::update( $b['id'], array( 'status' => $status ) );
		$labels = Bookings::status_labels();
		/* translators: %s: status. */
		Events::log( $b['id'], 'status', sprintf( __( 'Status changed to %s.', 'dev3-ai-booking-appointments' ), $labels[ $status ] ), $actor );
		$booking = Bookings::fresh( $b['id'] );
		self::status_changed( $booking, $b['status'] );
		if ( 'confirmed' === $status && in_array( $b['status'], array( 'pending', 'pending_payment' ), true ) ) {
			Mailer::send_customer( 'customer_confirmed', $booking );
		}
		return $booking;
	}

	/**
	 * Records a payment (Stripe, WooCommerce or an admin marking it paid) and confirms the booking.
	 *
	 * @param array  $b         Booking.
	 * @param float  $amount    Amount paid.
	 * @param string $method    stripe|woocommerce|later.
	 * @param array  $extra     stripe_intent, wc_order_id.
	 * @param string $actor     Actor.
	 * @return array
	 */
	public static function paid( array $b, $amount, $method, array $extra = array(), $actor = 'system' ) {
		if ( 'paid' === $b['payment_status'] && (float) $b['paid_amount'] >= (float) $amount ) {
			return $b;
		}
		$was      = $b['status'];
		$data     = array(
			'payment_status' => 'paid',
			'paid_amount'    => round( (float) $amount, 2 ),
			'payment_method' => $method,
		) + array_intersect_key( $extra, array_flip( array( 'stripe_intent', 'wc_order_id', 'stripe_session' ) ) );
		$activate = in_array( $was, array( 'pending_payment', 'expired' ), true ) || ( 'pending' === $was && 'woocommerce' === $method && 'confirmed' === Settings::get( 'default_status', 'confirmed' ) );
		if ( $activate ) {
			$data['status'] = 'pending' === Settings::get( 'default_status', 'confirmed' ) ? 'pending' : 'confirmed';
		}
		Bookings::update( $b['id'], $data );
		/* translators: %s: amount. */
		Events::log( $b['id'], 'payment', sprintf( __( 'Payment received: %s.', 'dev3-ai-booking-appointments' ), Settings::money( $amount, $b['currency'] ) ), $actor, array( 'method' => $method ) );
		$booking = Bookings::fresh( $b['id'] );
		/**
		 * Fires when a booking is paid.
		 *
		 * @param array $booking Booking.
		 */
		do_action( 'dev3bk_booking_paid', $booking );
		if ( $activate ) {
			self::status_changed( $booking, $was );
			Mailer::booking_created( $booking );
		}
		return $booking;
	}

	/**
	 * Expires unpaid holds (cron + lazily).
	 *
	 * @return int Count expired.
	 */
	public static function expire_holds() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Bookings::table() . ' WHERE status = %s AND created_at < %s LIMIT 200', 'pending_payment', Clock::sql( Clock::now() - 5 * MINUTE_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$n    = 0;
		foreach ( (array) $rows as $row ) {
			$b = Bookings::cast( $row );
			if ( Bookings::hold_expired( $b, Clock::now() ) ) {
				Bookings::update( $b['id'], array( 'status' => 'expired' ) );
				Events::log( $b['id'], 'expired', __( 'Unpaid booking expired and the time was released.', 'dev3-ai-booking-appointments' ) );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Fires the status hook.
	 *
	 * @param array  $booking Booking.
	 * @param string $old     Old status.
	 */
	private static function status_changed( array $booking, $old ) {
		/**
		 * Fires after a status change.
		 *
		 * @param array  $booking Booking.
		 * @param string $old     Old status.
		 */
		do_action( 'dev3bk_booking_status_changed', $booking, $old );
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
