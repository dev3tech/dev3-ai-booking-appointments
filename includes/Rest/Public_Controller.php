<?php
/**
 * Public routes for the booking widget, customer pages, calendar files and Stripe webhooks.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Rest;

use Dev3\AiBooking\Ai\Nl_Booking;
use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Policy;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Core3\Api\Ai;
use Dev3\AiBooking\Core3\Rest\Base_Controller;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Notify\Ics;
use Dev3\AiBooking\Payments\Payments;
use Dev3\AiBooking\Payments\Stripe;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Public controller.
 */
class Public_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$ref = '/public/bookings/(?P<ref>[A-Za-z0-9]{6,16})';
		$this->route( '/public/catalog', 'GET', 'catalog', 'catalog', 60 );
		$this->route( '/public/availability', 'GET', 'availability', 'availability', 90 );
		$this->route( '/public/nl', 'POST', 'nl', 'nl', 20 );
		$this->route( '/public/bookings', 'POST', 'create', 'book', 10 );
		$this->route( $ref, 'GET', 'show', 'manage', 60 );
		$this->route( $ref . '/cancel', 'POST', 'cancel', 'manage_write', 10 );
		$this->route( $ref . '/reschedule', 'POST', 'reschedule', 'manage_write', 10 );
		$this->route( $ref . '/payment', 'POST', 'payment', 'manage_write', 20 );
		$this->route( $ref . '/ics', 'GET', 'ics', 'ics', 30 );
		$this->route( '/public/my-bookings', 'GET', 'mine', 'manage', 30 );
		$this->route( '/feed/(?P<staff>\d+)', 'GET', 'feed', 'feed', 30 );
		register_rest_route(
			self::NS,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the Stripe signature.
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_calendar' ), 10, 4 );
	}

	/**
	 * Registers a public route.
	 *
	 * @param string $path     Path.
	 * @param string $method   Method.
	 * @param string $callback Method name.
	 * @param string $bucket   Rate bucket.
	 * @param int    $limit    Per-minute limit.
	 */
	private function route( $path, $method, $callback, $bucket, $limit ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => fn( $request ) => $this->public_permission( $request, 'dev3bk_' . $bucket, $limit ),
			)
		);
	}

	/**
	 * GET public/catalog.
	 *
	 * @return \WP_REST_Response
	 */
	public function catalog() {
		$services = array();
		foreach ( Services::all( true ) as $s ) {
			$staff = Staff::for_service( $s['id'] );
			if ( ! $staff ) {
				continue;
			}
			$services[] = array(
				'id'          => (int) $s['id'],
				'name'        => $s['name'],
				'description' => $s['description'],
				'category'    => $s['category'],
				'color'       => $s['color'],
				'duration'    => (int) $s['duration'],
				'price'       => (float) $s['price'],
				'price_label' => $s['price'] > 0 ? Settings::money( $s['price'] ) : __( 'Free', 'dev3-ai-booking-appointments' ),
				'deposit'     => $s['price'] > 0 && $s['deposit_percent'] > 0 && $s['deposit_percent'] < 100 ? Settings::money( Services::amount_due( $s ) ) : '',
				'capacity'    => (int) $s['capacity'],
				'location'    => $s['location'],
				'staff'       => $staff,
				'payments'    => Payments::methods( $s ),
			);
		}
		$staff = array();
		foreach ( Staff::all( true ) as $s ) {
			$staff[] = Staff::public_view( $s );
		}
		$user = wp_get_current_user();
		return $this->respond(
			array(
				'services'      => $services,
				'staff'         => $staff,
				'tz'            => Settings::timezone()->getName(),
				'time_format'   => (string) Settings::get( 'time_format', '24' ),
				'week_start'    => (int) Settings::get( 'week_start', 1 ),
				'currency'      => Settings::currency(),
				'phone_field'   => (string) Settings::get( 'phone_field', 'optional' ),
				'notes_field'   => (bool) Settings::get( 'notes_field', true ),
				'policy'        => Policy::summary(),
				'ai'            => (bool) Settings::get( 'ai_nl', true ),
				'payment_label' => Payments::labels(),
				'user'          => $user && $user->exists() ? array(
					'name'  => $user->display_name,
					'email' => $user->user_email,
				) : null,
			)
		);
	}

	/**
	 * Service from a request param, or WP_Error.
	 *
	 * @param mixed $id Id.
	 * @return array|\WP_Error
	 */
	private function service( $id ) {
		$s = Services::find( (int) $id );
		if ( ! $s || 'active' !== $s['status'] ) {
			return new \WP_Error( 'dev3bk_bad_service', __( 'Choose a service.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		return $s;
	}

	/**
	 * GET public/availability?service=&staff=&from=&to=&tz=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function availability( \WP_REST_Request $request ) {
		$service = $this->service( $request['service'] );
		if ( is_wp_error( $service ) ) {
			return $this->respond( $service );
		}
		$tz            = Clock::zone( (string) $request['tz'] );
		$today         = Clock::date( Clock::now(), $tz );
		$from          = \Dev3\AiBooking\Data\Exceptions::date( (string) $request['from'] );
		$from          = $from && $from >= $today ? $from : $today;
		$to            = \Dev3\AiBooking\Data\Exceptions::date( (string) $request['to'] );
		$cap           = gmdate( 'Y-m-d', strtotime( $from . ' +41 day UTC' ) );
		$to            = $to && $to >= $from ? min( $to, $cap ) : gmdate( 'Y-m-d', strtotime( $from . ' +13 day UTC' ) );
		$staff         = (int) $request['staff'];
		$data          = Engine::days( $service, $staff, $from, $to, $tz->getName() );
		$data['from']  = $from;
		$data['to']    = $to;
		$data['today'] = $today;
		$data['last']  = Clock::date( Clock::now() + Services::max_advance( $service ) * DAY_IN_SECONDS, $tz );
		$data['next']  = $data['days'] ? '' : Engine::next_available( $service, $staff, gmdate( 'Y-m-d', strtotime( $to . ' +1 day UTC' ) ), $tz->getName() );
		return $this->respond( $data );
	}

	/**
	 * POST public/nl { text, service, staff, tz }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function nl( \WP_REST_Request $request ) {
		return $this->respond(
			Nl_Booking::suggest(
				(string) $request['text'],
				array(
					'service_id' => (int) $request['service'],
					'staff_id'   => (int) $request['staff'],
					'tz'         => (string) $request['tz'],
					'limit'      => 12,
				),
				'storefront'
			)
		);
	}

	/**
	 * POST public/bookings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ) {
		$p = $request->get_json_params();
		$p = is_array( $p ) && $p ? $p : $request->get_params();
		if ( ! empty( $p['website'] ) ) { // Honeypot.
			return $this->respond( new \WP_Error( 'dev3bk_spam', __( 'Please try again.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		$booking = Manager::create(
			array(
				'service_id' => (int) ( $p['service'] ?? 0 ),
				'staff_id'   => (int) ( $p['staff'] ?? 0 ),
				'start'      => (string) ( $p['start'] ?? '' ),
				'seats'      => (int) ( $p['seats'] ?? 1 ),
				'name'       => (string) ( $p['name'] ?? '' ),
				'email'      => (string) ( $p['email'] ?? '' ),
				'phone'      => (string) ( $p['phone'] ?? '' ),
				'notes'      => Settings::get( 'notes_field', true ) ? (string) ( $p['notes'] ?? '' ) : '',
				'tz'         => (string) ( $p['tz'] ?? '' ),
				'payment'    => (string) ( $p['payment'] ?? '' ),
				'source'     => ! empty( $p['ai'] ) ? 'ai' : 'widget',
				'user_id'    => get_current_user_id(),
			)
		);
		if ( is_wp_error( $booking ) ) {
			return $this->respond( $booking );
		}
		$next = Payments::next_step( $booking );
		if ( is_wp_error( $next ) ) {
			return $this->respond( $next );
		}
		return $this->respond(
			array(
				'booking' => Presenter::customer( Bookings::fresh( $booking['id'] ) ),
				'next'    => $next,
			),
			201
		);
	}

	/**
	 * Booking the caller may manage (key, or the logged-in owner).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function owned( \WP_REST_Request $request ) {
		$b = Bookings::by_ref( (string) $request['ref'] );
		if ( $b && Bookings::key_ok( $b, (string) $request['key'] ) ) {
			return $b;
		}
		if ( $b && is_user_logged_in() ) {
			$customer = Customers::get( $b['customer_id'] );
			$user     = wp_get_current_user();
			if ( $customer && ( (int) $customer['user_id'] === (int) $user->ID || strtolower( $customer['email'] ) === strtolower( $user->user_email ) ) ) {
				return $b;
			}
		}
		return new \WP_Error( 'dev3bk_not_found', __( 'We could not find that booking. Check the link in your email.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
	}

	/**
	 * GET public/bookings/<ref>?key=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ) {
		$b = $this->owned( $request );
		return $this->respond( is_wp_error( $b ) ? $b : Presenter::customer( $b ) );
	}

	/**
	 * POST public/bookings/<ref>/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cancel( \WP_REST_Request $request ) {
		$b = $this->owned( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$done = Manager::cancel( $b, (string) $request['reason'], 'customer' );
		return $this->respond( is_wp_error( $done ) ? $done : Presenter::customer( $done ) );
	}

	/**
	 * POST public/bookings/<ref>/reschedule { start, staff }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reschedule( \WP_REST_Request $request ) {
		$b = $this->owned( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$ts = Clock::parse( (string) $request['start'] );
		if ( ! $ts ) {
			return $this->respond( new \WP_Error( 'dev3bk_bad_time', __( 'Choose a time.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		$done = Manager::reschedule( $b, $ts, (int) $request['staff'], 'customer' );
		return $this->respond( is_wp_error( $done ) ? $done : Presenter::customer( $done ) );
	}

	/**
	 * POST public/bookings/<ref>/payment: re-checks Stripe after the redirect back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function payment( \WP_REST_Request $request ) {
		$b = $this->owned( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		if ( 'stripe' === $b['payment_method'] ) {
			$b = Stripe::verify( $b );
		}
		return $this->respond( Presenter::customer( $b ) );
	}

	/**
	 * GET public/bookings/<ref>/ics?key=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ics( \WP_REST_Request $request ) {
		$b = $this->owned( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$res = new \WP_REST_Response( Ics::booking( $b ) );
		$res->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$res->header( 'Content-Disposition', 'attachment; filename="booking-' . $b['ref'] . '.ics"' );
		return $res;
	}

	/**
	 * GET feed/<staff>?token=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function feed( \WP_REST_Request $request ) {
		$staff = Staff::get( (int) $request['staff'] );
		$token = (string) $request['token'];
		if ( ! $staff || '' === $staff['feed_token'] || '' === $token || ! hash_equals( $staff['feed_token'], $token ) ) {
			return $this->respond( new \WP_Error( 'dev3bk_not_found', __( 'Calendar not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) ) );
		}
		$res = new \WP_REST_Response( Ics::feed( $staff ) );
		$res->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$res->header( 'Content-Disposition', 'inline; filename="calendar.ics"' );
		return $res;
	}

	/**
	 * GET public/my-bookings (logged-in).
	 *
	 * @return \WP_REST_Response
	 */
	public function mine() {
		if ( ! is_user_logged_in() ) {
			return $this->respond( new \WP_Error( 'dev3bk_login', __( 'Please log in to see your bookings.', 'dev3-ai-booking-appointments' ), array( 'status' => 401 ) ) );
		}
		$user = wp_get_current_user();
		$ids  = array();
		$c    = Customers::by_email( $user->user_email );
		if ( $c ) {
			$ids[] = (int) $c['id'];
		}
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Customers::table() . ' WHERE user_id = %d', $user->ID ) ) as $id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$ids[] = (int) $id;
		}
		$rows = array();
		foreach ( array_unique( $ids ) as $cid ) {
			$rows = array_merge(
				$rows,
				Bookings::search(
					array(
						'customer' => $cid,
						'per_page' => 100,
					)
				)['rows']
			);
		}
		$rows = array_filter( $rows, static fn( $b ) => 'expired' !== $b['status'] );
		usort( $rows, static fn( $a, $b ) => strcmp( $b['starts_at'], $a['starts_at'] ) );
		return $this->respond( array( 'bookings' => array_values( array_map( array( Presenter::class, 'customer' ), $rows ) ) ) );
	}

	/**
	 * POST stripe/webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function webhook( \WP_REST_Request $request ) {
		return $this->respond( Stripe::webhook( (string) $request->get_body(), (string) $request->get_header( 'stripe_signature' ) ) );
	}

	/**
	 * Serves text/calendar responses raw (not JSON-encoded).
	 *
	 * @param bool              $served  Served.
	 * @param \WP_HTTP_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public function serve_calendar( $served, $result, $request, $server ) {
		unset( $request );
		$headers = $result instanceof \WP_HTTP_Response ? $result->get_headers() : array();
		if ( $served || empty( $headers['Content-Type'] ) || 0 !== strpos( $headers['Content-Type'], 'text/calendar' ) || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		$server->send_header( 'Content-Type', $headers['Content-Type'] );
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, not HTML.
		return true;
	}

	/**
	 * AI status for the widget (kept for API compatibility).
	 *
	 * @return bool
	 */
	public static function ai_available() {
		return (bool) Settings::get( 'ai_nl', true ) && Ai::enabled( 'storefront' );
	}
}
