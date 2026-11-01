<?php
/**
 * Booking views for the admin app, the customer pages and emails, plus links (manage, ICS, Google).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Booking;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Presenter.
 */
class Presenter {

	/**
	 * Manage page URL with the secure key.
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function manage_url( array $b ) {
		$page = (int) Settings::get( 'account_page', 0 );
		$base = $page && get_post( $page ) ? get_permalink( $page ) : home_url( '/' );
		return add_query_arg(
			array(
				'dev3bk_ref' => $b['ref'],
				'dev3bk_key' => $b['access_key'],
			),
			$base
		);
	}

	/**
	 * Public ICS download URL.
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function ics_url( array $b ) {
		return add_query_arg( 'key', $b['access_key'], rest_url( 'dev3bk/v1/public/bookings/' . $b['ref'] . '/ics' ) );
	}

	/**
	 * Google Calendar "add event" link.
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function google_url( array $b ) {
		$service = Services::get( $b['service_id'] );
		$staff   = Staff::get( $b['staff_id'] );
		$title   = self::title( $b );
		$details = $staff ? sprintf( /* translators: %s: staff name. */ __( 'With %s', 'dev3-ai-booking-appointments' ), $staff['name'] ) : '';
		$details = trim( $details . "\n" . __( 'Manage your booking:', 'dev3-ai-booking-appointments' ) . ' ' . self::manage_url( $b ) );
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $title ),
				'dates'    => gmdate( 'Ymd\THis\Z', Clock::ts( $b['starts_at'] ) ) . '/' . gmdate( 'Ymd\THis\Z', Clock::ts( $b['ends_at'] ) ),
				'details'  => rawurlencode( $details ),
				'location' => rawurlencode( $service ? $service['location'] : '' ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Event title: "Service – Business".
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function title( array $b ) {
		$service = Services::get( $b['service_id'] );
		$name    = $service ? $service['name'] : __( 'Appointment', 'dev3-ai-booking-appointments' );
		$biz     = self::business_name();
		return $biz ? $name . ' – ' . $biz : $name;
	}

	/**
	 * Business name (setting, else site title).
	 *
	 * @return string
	 */
	public static function business_name() {
		$name = trim( (string) Settings::get( 'business_name', '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Time format string.
	 *
	 * @return string
	 */
	public static function time_format() {
		return '12' === (string) Settings::get( 'time_format', '24' ) ? 'g:i A' : 'H:i';
	}

	/**
	 * Common view.
	 *
	 * @param array  $b   Booking.
	 * @param string $tz  Display zone ('' = customer's).
	 * @return array
	 */
	public static function base( array $b, $tz = '' ) {
		$service = Services::get( $b['service_id'] );
		$staff   = Staff::get( $b['staff_id'] );
		$zone    = Clock::zone( $tz ? $tz : $b['customer_tz'] );
		$start   = Clock::ts( $b['starts_at'] );
		$end     = Clock::ts( $b['ends_at'] );
		$labels  = Bookings::status_labels();
		return array(
			'id'             => (int) $b['id'],
			'ref'            => $b['ref'],
			'status'         => $b['status'],
			'status_label'   => $labels[ $b['status'] ] ?? $b['status'],
			'service_id'     => (int) $b['service_id'],
			'service'        => $service ? $service['name'] : __( '(deleted service)', 'dev3-ai-booking-appointments' ),
			'service_color'  => $service ? $service['color'] : '#64748b',
			'location'       => $service ? $service['location'] : '',
			'duration'       => (int) round( ( $end - $start ) / 60 ),
			'staff_id'       => (int) $b['staff_id'],
			'staff'          => $staff ? $staff['name'] : '',
			'staff_color'    => $staff ? $staff['color'] : '#64748b',
			'start'          => Clock::iso( $start ),
			'end'            => Clock::iso( $end ),
			'tz'             => $zone->getName(),
			'date_label'     => Clock::i18n( $start, $zone, (string) get_option( 'date_format', 'F j, Y' ) ),
			'day_label'      => Clock::i18n( $start, $zone, 'l' ),
			'time_label'     => Clock::format( $start, $zone, self::time_format() ) . ' – ' . Clock::format( $end, $zone, self::time_format() ),
			'seats'          => (int) $b['seats'],
			'amount'         => (float) $b['amount'],
			'amount_due'     => (float) $b['amount_due'],
			'paid_amount'    => (float) $b['paid_amount'],
			'amount_label'   => $b['amount'] > 0 ? Settings::money( $b['amount'], $b['currency'] ) : __( 'Free', 'dev3-ai-booking-appointments' ),
			'currency'       => $b['currency'],
			'payment_method' => $b['payment_method'],
			'payment_status' => $b['payment_status'],
		);
	}

	/**
	 * Customer-facing view with policy flags and links.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	public static function customer( array $b ) {
		$view                   = self::base( $b );
		$view['notes']          = $b['notes'];
		$view['can_cancel']     = true === Policy::can_cancel( $b );
		$view['can_reschedule'] = true === Policy::can_reschedule( $b );
		$view['refund_amount']  = Policy::refund_amount( $b );
		$view['policy']         = Policy::summary();
		$view['ics_url']        = self::ics_url( $b );
		$view['google_url']     = self::google_url( $b );
		$view['manage_url']     = self::manage_url( $b );
		$view['key']            = $b['access_key'];
		$view['upcoming']       = Clock::ts( $b['starts_at'] ) > Clock::now() && in_array( $b['status'], array( 'pending', 'confirmed', 'pending_payment' ), true );
		$view['pay_url']        = '';
		return $view;
	}

	/**
	 * Admin list/calendar view.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	public static function admin( array $b ) {
		$view                = self::base( $b, Settings::timezone()->getName() );
		$customer            = Customers::get( $b['customer_id'] );
		$view['customer']    = $customer ? array(
			'id'    => (int) $customer['id'],
			'name'  => $customer['name'],
			'email' => $customer['email'],
			'phone' => $customer['phone'],
		) : null;
		$risk                = is_array( $b['risk'] ) && isset( $b['risk']['score'] ) ? $b['risk'] : Risk::score( $b );
		$view['risk']        = array(
			'score'   => (int) $risk['score'],
			'level'   => (string) $risk['level'],
			'reasons' => (array) $risk['reasons'],
			'ai'      => $risk['ai'] ?? null,
		);
		$view['notes']       = $b['notes'];
		$view['source']      = $b['source'];
		$view['customer_tz'] = $b['customer_tz'];
		$view['wc_order_id'] = (int) $b['wc_order_id'];
		$view['created_at']  = Clock::iso( Clock::ts( $b['created_at'] ) );
		$view['local']       = Clock::format( Clock::ts( $b['starts_at'] ), Settings::timezone(), 'Y-m-d\TH:i' );
		$view['local_end']   = Clock::format( Clock::ts( $b['ends_at'] ), Settings::timezone(), 'Y-m-d\TH:i' );
		$view['reschedules'] = (int) $b['reschedules'];
		$view['reminders']   = (array) $b['reminders'];
		$view['manage_url']  = self::manage_url( $b );
		$view['order_url']   = $b['wc_order_id'] && function_exists( 'wc_get_order' ) ? self::order_url( (int) $b['wc_order_id'] ) : '';
		return $view;
	}

	/**
	 * Admin detail: view + timeline.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	public static function detail( array $b ) {
		$view           = self::admin( $b );
		$view['events'] = array_map(
			static fn( $e ) => array(
				'id'      => $e['id'],
				'type'    => $e['type'],
				'actor'   => $e['actor'],
				'message' => $e['message'],
				'at'      => Clock::iso( Clock::ts( $e['created_at'] ) ),
			),
			Events::for_booking( $b['id'] )
		);
		return $view;
	}

	/**
	 * HPOS-safe order edit URL.
	 *
	 * @param int $order_id Order.
	 * @return string
	 */
	public static function order_url( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? (string) $order->get_edit_order_url() : '';
	}
}
