<?php
/**
 * Admin routes (manage capability + strict nonce): dashboard, catalog, schedule, bookings,
 * calendar, customers, templates, payments and the DEV3 AI admin tools.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Rest;

use Dev3\AiBooking\Ai\Nl_Booking;
use Dev3\AiBooking\Ai\Writers;
use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Booking\Risk;
use Dev3\AiBooking\Core3\Rest\Base_Controller;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Data\Exceptions;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Frontend\Pages;
use Dev3\AiBooking\Notify\Mailer;
use Dev3\AiBooking\Notify\Templates;
use Dev3\AiBooking\Payments\Payments;
use Dev3\AiBooking\Payments\Stripe;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$id = '(?P<id>\d+)';
		$this->route( '/dashboard', 'GET', 'dashboard' );
		$this->route( '/services', 'GET', 'services' );
		$this->route( '/services', 'POST', 'service_save' );
		$this->route( '/services/' . $id, 'PUT', 'service_save' );
		$this->route( '/services/' . $id, 'DELETE', 'service_delete' );
		$this->route( '/staff', 'GET', 'staff' );
		$this->route( '/staff', 'POST', 'staff_save' );
		$this->route( '/staff/' . $id, 'PUT', 'staff_save' );
		$this->route( '/staff/' . $id, 'DELETE', 'staff_delete' );
		$this->route( '/staff/' . $id . '/feed-token', 'POST', 'feed_token' );
		$this->route( '/exceptions', 'GET', 'exceptions' );
		$this->route( '/exceptions', 'POST', 'exception_create' );
		$this->route( '/exceptions/' . $id, 'DELETE', 'exception_delete' );
		$this->route( '/schedule', 'GET', 'schedule' );
		$this->route( '/schedule', 'PUT', 'schedule_save' );
		$this->route( '/bookings', 'GET', 'bookings' );
		$this->route( '/bookings', 'POST', 'booking_create' );
		$this->route( '/bookings/export', 'GET', 'export' );
		$this->route( '/bookings/bulk', 'POST', 'bulk' );
		$this->route( '/bookings/' . $id, 'GET', 'booking' );
		$this->route( '/bookings/' . $id, 'PATCH', 'booking_patch' );
		$this->route( '/bookings/' . $id . '/status', 'POST', 'booking_status' );
		$this->route( '/bookings/' . $id . '/reschedule', 'POST', 'booking_reschedule' );
		$this->route( '/bookings/' . $id . '/payment', 'POST', 'booking_payment' );
		$this->route( '/bookings/' . $id . '/notify', 'POST', 'booking_notify' );
		$this->route( '/bookings/' . $id . '/risk', 'POST', 'booking_risk' );
		$this->route( '/calendar', 'GET', 'calendar' );
		$this->route( '/availability', 'GET', 'availability' );
		$this->route( '/ai/nl', 'POST', 'ai_nl' );
		$this->route( '/customers', 'GET', 'customers' );
		$this->route( '/customers/' . $id, 'GET', 'customer' );
		$this->route( '/customers/' . $id, 'PATCH', 'customer_patch' );
		$this->route( '/templates', 'GET', 'templates' );
		$this->route( '/templates/(?P<tpl>[a-z0-9_]+)', 'PUT', 'template_save' );
		$this->route( '/templates/(?P<tpl>[a-z0-9_]+)/ai', 'POST', 'template_ai' );
		$this->route( '/templates/(?P<tpl>[a-z0-9_]+)/preview', 'POST', 'template_preview' );
		$this->route( '/templates/(?P<tpl>[a-z0-9_]+)/test', 'POST', 'template_test' );
		$this->route( '/payments/stripe', 'GET', 'stripe' );
		$this->route( '/payments/stripe', 'PUT', 'stripe_save' );
		$this->route( '/pages', 'POST', 'pages' );
	}

	/**
	 * Registers an admin route.
	 *
	 * @param string $path     Path.
	 * @param string $method   Method.
	 * @param string $callback Method name.
	 */
	private function route( $path, $method, $callback ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Request params (JSON first).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function params( \WP_REST_Request $request ) {
		$p = $request->get_json_params();
		return is_array( $p ) && $p ? $p : $request->get_params();
	}

	/**
	 * Booking by route id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function find( \WP_REST_Request $request ) {
		$b = Bookings::fresh( (int) $request['id'] );
		return $b ? $b : new \WP_Error( 'dev3bk_not_found', __( 'Booking not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
	}

	/**
	 * Detail response or error.
	 *
	 * @param array|\WP_Error $b Booking.
	 * @return \WP_REST_Response
	 */
	private function detail( $b ) {
		return $this->respond( is_wp_error( $b ) ? $b : Presenter::detail( Bookings::fresh( $b['id'] ) ) );
	}

	/**
	 * GET dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		global $wpdb;
		Manager::expire_holds();
		$tz         = Settings::timezone();
		$now        = Clock::now();
		$today      = Clock::date( $now, $tz );
		$t0         = Clock::local( $today, 0, $tz );
		$t1         = Clock::local( $today, 1440, $tz );
		$t          = Bookings::table();
		$live       = array( 'pending', 'confirmed', 'completed', 'pending_payment' );
		$today_rows = Bookings::between( Clock::sql( $t0 ), Clock::sql( $t1 ), array( 'pending', 'confirmed', 'completed', 'no_show', 'pending_payment' ) );
		$week       = Bookings::between( Clock::sql( $now ), Clock::sql( $now + 7 * DAY_IN_SECONDS ), $live );
		$revenue    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(paid_amount),0) FROM {$t} WHERE created_at >= %s AND payment_status = 'paid'", Clock::sql( $now - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$past       = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'no_show') AS ns, SUM(status IN ('completed','no_show')) AS total FROM {$t} WHERE starts_at >= %s AND starts_at < %s", Clock::sql( $now - 90 * DAY_IN_SECONDS ), Clock::sql( $now ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending    = Bookings::search(
			array(
				'status'   => 'pending',
				'per_page' => 10,
				'order'    => 'asc',
			)
		);
		$risky      = array();
		foreach ( Bookings::between( Clock::sql( $now ), Clock::sql( $now + 14 * DAY_IN_SECONDS ), array( 'pending', 'confirmed' ) ) as $b ) {
			$r = isset( $b['risk']['score'] ) ? $b['risk'] : Risk::score( $b );
			if ( 'high' === ( $r['level'] ?? '' ) ) {
				$risky[] = Presenter::admin( $b );
			}
		}
		$services = Services::all();
		$staff    = Staff::all();
		$hours_ok = false;
		foreach ( $staff as $s ) {
			list( $week_hours ) = Staff::week( $s );
			foreach ( $week_hours as $list ) {
				$hours_ok = $hours_ok || ! empty( $list );
			}
		}
		$online = ( Settings::get( 'stripe_enabled' ) && Stripe::ready() ) || Payments::woo_ready();
		$needs  = false;
		foreach ( $services as $s ) {
			$needs = $needs || ( 'required' === $s['payment_mode'] && $s['price'] > 0 );
		}
		$total = (int) ( $past['total'] ?? 0 );
		return $this->respond(
			array(
				'kpis'       => array(
					'today'         => count( array_filter( $today_rows, static fn( $b ) => 'cancelled' !== $b['status'] ) ),
					'week'          => count( $week ),
					'revenue'       => round( $revenue, 2 ),
					'revenue_label' => Settings::money( $revenue ),
					'no_show_rate'  => $total ? round( 100 * (int) $past['ns'] / $total, 1 ) : 0,
					'pending'       => (int) ( $pending['counts']['pending'] ?? 0 ),
				),
				'today'      => array_map( array( Presenter::class, 'admin' ), $today_rows ),
				'pending'    => array_map( array( Presenter::class, 'admin' ), $pending['rows'] ),
				'risky'      => array_slice( $risky, 0, 8 ),
				'checklist'  => array(
					'services' => count( $services ) > 0,
					'staff'    => count( $staff ) > 0 && $hours_ok,
					'page'     => (bool) Settings::get( 'booking_page' ) && get_post( (int) Settings::get( 'booking_page' ) ),
					'payments' => ! $needs || $online,
					'email'    => (bool) Settings::get( 'business_email' ),
					'ai'       => (bool) \Dev3\AiBooking\Core3\Core\Settings_Store::api_key(),
				),
				'links'      => array(
					'booking_page' => Settings::get( 'booking_page' ) ? (string) get_permalink( (int) Settings::get( 'booking_page' ) ) : '',
					'account_page' => Settings::get( 'account_page' ) ? (string) get_permalink( (int) Settings::get( 'account_page' ) ) : '',
				),
				'tz'         => $tz->getName(),
				'today_date' => $today,
			)
		);
	}

	/**
	 * GET services.
	 *
	 * @return \WP_REST_Response
	 */
	public function services() {
		return $this->respond( array( 'services' => Services::all() ) );
	}

	/**
	 * POST services / PUT services/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function service_save( \WP_REST_Request $request ) {
		$saved = Services::save( $this->params( $request ), (int) $request['id'] );
		return $this->respond( $saved, $request['id'] ? 200 : 201 );
	}

	/**
	 * DELETE services/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function service_delete( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Services::remove( (int) $request['id'] ) ) );
	}

	/**
	 * Staff admin view (adds the feed URL).
	 *
	 * @param array $s Staff.
	 * @return array
	 */
	private function staff_view( array $s ) {
		$s['feed_url'] = add_query_arg( 'token', $s['feed_token'], rest_url( 'dev3bk/v1/feed/' . $s['id'] ) );
		unset( $s['feed_token'] );
		$s['exceptions'] = Exceptions::for_staff( $s['id'] );
		return $s;
	}

	/**
	 * GET staff.
	 *
	 * @return \WP_REST_Response
	 */
	public function staff() {
		return $this->respond(
			array(
				'staff'     => array_map( array( $this, 'staff_view' ), Staff::all() ),
				'timezones' => timezone_identifiers_list(),
			)
		);
	}

	/**
	 * POST staff / PUT staff/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function staff_save( \WP_REST_Request $request ) {
		$saved = Staff::save( $this->params( $request ), (int) $request['id'] );
		return $this->respond( is_wp_error( $saved ) ? $saved : $this->staff_view( $saved ), $request['id'] ? 200 : 201 );
	}

	/**
	 * DELETE staff/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function staff_delete( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Staff::remove( (int) $request['id'] ) ) );
	}

	/**
	 * POST staff/<id>/feed-token: rotates the private iCal feed token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function feed_token( \WP_REST_Request $request ) {
		$s = Staff::get( (int) $request['id'] );
		if ( ! $s ) {
			return $this->respond( new \WP_Error( 'dev3bk_not_found', __( 'Staff member not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) ) );
		}
		Staff::update( $s['id'], array( 'feed_token' => wp_generate_password( 32, false, false ) ) );
		return $this->respond( $this->staff_view( Staff::find( $s['id'] ) ) );
	}

	/**
	 * GET exceptions?staff=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function exceptions( \WP_REST_Request $request ) {
		return $this->respond( array( 'exceptions' => Exceptions::for_staff( (int) $request['staff'] ) ) );
	}

	/**
	 * POST exceptions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function exception_create( \WP_REST_Request $request ) {
		return $this->respond( Exceptions::create( $this->params( $request ) ), 201 );
	}

	/**
	 * DELETE exceptions/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function exception_delete( \WP_REST_Request $request ) {
		$ok = Exceptions::delete( (int) $request['id'] );
		Exceptions::forget();
		return $this->respond( array( 'deleted' => $ok ) );
	}

	/**
	 * GET schedule (business hours + closures).
	 *
	 * @return \WP_REST_Response
	 */
	public function schedule() {
		return $this->respond(
			array(
				'hours'      => Settings::sanitize_week( Settings::get( 'business_hours', array() ) ),
				'breaks'     => Settings::sanitize_week( Settings::get( 'business_breaks', array() ) ),
				'exceptions' => Exceptions::for_staff( 0 ),
				'timezone'   => Settings::timezone()->getName(),
			)
		);
	}

	/**
	 * PUT schedule { hours, breaks }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function schedule_save( \WP_REST_Request $request ) {
		$p = $this->params( $request );
		\Dev3\AiBooking\Core3\Core\Settings_Store::update(
			array(
				'business_hours'  => $p['hours'] ?? Settings::get( 'business_hours' ),
				'business_breaks' => $p['breaks'] ?? Settings::get( 'business_breaks' ),
			)
		);
		return $this->schedule();
	}

	/**
	 * Admin search args from a request (dates are business-local Y-m-d).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function search_args( \WP_REST_Request $request ) {
		$tz   = Settings::timezone();
		$from = Exceptions::date( (string) $request['from'] );
		$to   = Exceptions::date( (string) $request['to'] );
		return array(
			'status'   => sanitize_key( (string) $request['status'] ),
			'staff'    => (int) $request['staff'],
			'service'  => (int) $request['service'],
			'customer' => (int) $request['customer'],
			'payment'  => sanitize_key( (string) $request['payment'] ),
			'search'   => sanitize_text_field( (string) $request['search'] ),
			'from'     => $from ? Clock::sql( Clock::local( $from, 0, $tz ) ) : '',
			'to'       => $to ? Clock::sql( Clock::local( $to, 1440, $tz ) ) : '',
			'page'     => max( 1, (int) $request['page'] ),
			'per_page' => $request['per_page'] ? (int) $request['per_page'] : 20,
			'order'    => 'asc' === $request['order'] ? 'asc' : 'desc',
		);
	}

	/**
	 * GET bookings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bookings( \WP_REST_Request $request ) {
		Manager::expire_holds();
		$res = Bookings::search( $this->search_args( $request ) );
		return $this->respond(
			array(
				'rows'   => array_map( array( Presenter::class, 'admin' ), $res['rows'] ),
				'total'  => $res['total'],
				'counts' => $res['counts'],
			)
		);
	}

	/**
	 * GET bookings/export (CSV text).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$args             = $this->search_args( $request );
		$args['per_page'] = 500;
		$args['page']     = 1;
		$res              = Bookings::search( $args );
		$out              = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'ref', 'status', 'service', 'staff', 'customer', 'email', 'phone', 'start', 'end', 'timezone', 'amount', 'paid', 'payment_method', 'payment_status', 'source', 'notes' ) );
		foreach ( $res['rows'] as $b ) {
			$v = Presenter::admin( $b );
			fputcsv(
				$out,
				array_map(
					array( self::class, 'csv_cell' ),
					array( $v['ref'], $v['status'], $v['service'], $v['staff'], $v['customer']['name'] ?? '', $v['customer']['email'] ?? '', $v['customer']['phone'] ?? '', $v['start'], $v['end'], $v['tz'], $v['amount'], $v['paid_amount'], $v['payment_method'], $v['payment_status'], $v['source'], $v['notes'] )
				)
			);
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $this->respond(
			array(
				'filename' => 'bookings-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => $csv,
				'count'    => count( $res['rows'] ),
			)
		);
	}

	/**
	 * Neutralises spreadsheet formulas in CSV cells.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Start time from { local: "Y-m-d H:i" (business tz) } or { start: ISO }.
	 *
	 * @param array $p Params.
	 * @return int Unix (0 = invalid).
	 */
	private function start_from( array $p ) {
		$local = (string) ( $p['local'] ?? '' );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})$/', $local, $m ) && Exceptions::date( $m[1] ) ) {
			return (int) Clock::local( $m[1], (int) $m[2] * 60 + (int) $m[3], Settings::timezone() );
		}
		return Clock::parse( (string) ( $p['start'] ?? '' ) );
	}

	/**
	 * POST bookings (admin create).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_create( \WP_REST_Request $request ) {
		$p        = $this->params( $request );
		$customer = ! empty( $p['customer_id'] ) ? Customers::get( (int) $p['customer_id'] ) : null;
		$b        = Manager::create(
			array(
				'admin'          => true,
				'service_id'     => (int) ( $p['service'] ?? 0 ),
				'staff_id'       => (int) ( $p['staff'] ?? 0 ),
				'start'          => $this->start_from( $p ),
				'seats'          => (int) ( $p['seats'] ?? 1 ),
				'name'           => $customer ? $customer['name'] : (string) ( $p['name'] ?? '' ),
				'email'          => $customer ? $customer['email'] : (string) ( $p['email'] ?? '' ),
				'phone'          => $customer ? $customer['phone'] : (string) ( $p['phone'] ?? '' ),
				'notes'          => (string) ( $p['notes'] ?? '' ),
				'tz'             => (string) ( $p['tz'] ?? Settings::timezone()->getName() ),
				'force'          => ! empty( $p['force'] ),
				'status'         => (string) ( $p['status'] ?? 'confirmed' ),
				'payment_status' => (string) ( $p['payment_status'] ?? '' ),
				'source'         => ! empty( $p['ai'] ) ? 'ai' : 'admin',
				'silent'         => empty( $p['notify'] ),
			)
		);
		return is_wp_error( $b ) ? $this->respond( $b ) : $this->respond( Presenter::detail( $b ), 201 );
	}

	/**
	 * POST bookings/bulk { ids[], action: confirm|complete|no_show|cancel }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$p      = $this->params( $request );
		$action = sanitize_key( (string) ( $p['action'] ?? '' ) );
		$map    = array(
			'confirm'  => 'confirmed',
			'complete' => 'completed',
			'no_show'  => 'no_show',
			'cancel'   => 'cancelled',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return $this->respond( new \WP_Error( 'dev3bk_bad_action', __( 'Unknown bulk action.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		$done   = 0;
		$failed = 0;
		foreach ( array_slice( array_unique( array_map( 'intval', (array) ( $p['ids'] ?? array() ) ) ), 0, 200 ) as $id ) {
			$b = $id ? Bookings::fresh( $id ) : null;
			if ( ! $b ) {
				++$failed;
				continue;
			}
			$res = 'cancel' === $action ? Manager::cancel( $b, '', 'admin' ) : Manager::set_status( $b, $map[ $action ] );
			if ( is_wp_error( $res ) ) {
				++$failed;
			} else {
				++$done;
			}
		}
		return $this->respond(
			array(
				'done'   => $done,
				'failed' => $failed,
			)
		);
	}

	/**
	 * GET bookings/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking( \WP_REST_Request $request ) {
		return $this->detail( $this->find( $request ) );
	}

	/**
	 * PATCH bookings/<id> { notes }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_patch( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$p = $this->params( $request );
		if ( isset( $p['notes'] ) ) {
			Bookings::update( $b['id'], array( 'notes' => mb_substr( sanitize_textarea_field( (string) $p['notes'] ), 0, 1000 ) ) );
			Events::log( $b['id'], 'note', __( 'Notes updated.', 'dev3-ai-booking-appointments' ), 'admin' );
		}
		return $this->detail( $b );
	}

	/**
	 * POST bookings/<id>/status { status, reason, refund }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_status( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$status = sanitize_key( (string) $request['status'] );
		$res    = 'cancelled' === $status ? Manager::cancel( $b, (string) $request['reason'], 'admin', (bool) $request['refund'] ) : Manager::set_status( $b, $status );
		return $this->detail( $res );
	}

	/**
	 * POST bookings/<id>/reschedule { start, staff, force, notify }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_reschedule( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$ts = $this->start_from( $this->params( $request ) );
		if ( ! $ts ) {
			return $this->respond( new \WP_Error( 'dev3bk_bad_time', __( 'Choose a time.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		return $this->detail( Manager::reschedule( $b, $ts, (int) $request['staff'], 'admin', (bool) $request['force'] ) );
	}

	/**
	 * POST bookings/<id>/payment { action: paid|unpaid|refund, amount }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_payment( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$action = sanitize_key( (string) $request['action'] );
		$amount = is_numeric( $request['amount'] ) ? max( 0, (float) $request['amount'] ) : 0;
		if ( 'paid' === $action ) {
			$method = in_array( $b['payment_method'], array( 'stripe', 'woocommerce' ), true ) && 'paid' !== $b['payment_status'] ? 'later' : ( 'none' === $b['payment_method'] ? 'later' : $b['payment_method'] );
			return $this->detail( Manager::paid( $b, $amount > 0 ? $amount : (float) $b['amount'], $method, array(), 'admin' ) );
		}
		if ( 'refund' === $action ) {
			$res = Payments::refund( $b, $amount > 0 ? $amount : (float) $b['paid_amount'] );
			return is_wp_error( $res ) ? $this->respond( $res ) : $this->detail( $b );
		}
		return $this->respond( new \WP_Error( 'dev3bk_bad_action', __( 'Unknown payment action.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
	}

	/**
	 * POST bookings/<id>/notify { template }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_notify( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$tpl = sanitize_key( (string) $request['template'] );
		$def = Templates::get( $tpl );
		if ( ! $def || 'customer' !== $def['audience'] ) {
			return $this->respond( new \WP_Error( 'dev3bk_bad_template', __( 'Choose a customer email.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		if ( ! $def['enabled'] ) {
			return $this->respond( new \WP_Error( 'dev3bk_template_off', __( 'That email is turned off in Notifications.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		$sent = Mailer::send_customer( $tpl, $b );
		if ( $sent && 0 === strpos( $tpl, 'reminder_' ) ) {
			$flags         = (array) $b['reminders'];
			$flags[ $tpl ] = Clock::sql( Clock::now() );
			Bookings::update( $b['id'], array( 'reminders' => $flags ) );
		}
		if ( ! $sent ) {
			return $this->respond( new \WP_Error( 'dev3bk_mail_failed', __( 'The email could not be sent. Check your site\'s email settings.', 'dev3-ai-booking-appointments' ), array( 'status' => 500 ) ) );
		}
		return $this->detail( $b );
	}

	/**
	 * POST bookings/<id>/risk: DEV3 AI hint for the no-show risk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function booking_risk( \WP_REST_Request $request ) {
		$b = $this->find( $request );
		if ( is_wp_error( $b ) ) {
			return $this->respond( $b );
		}
		$risk = Writers::risk( $b );
		return is_wp_error( $risk ) ? $this->respond( $risk ) : $this->detail( $b );
	}

	/**
	 * GET calendar?from=&to=&staff=&service= (business-local dates, inclusive).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function calendar( \WP_REST_Request $request ) {
		Manager::expire_holds();
		$tz   = Settings::timezone();
		$from = Exceptions::date( (string) $request['from'] );
		$to   = Exceptions::date( (string) $request['to'] );
		$from = $from ? $from : Clock::date( Clock::now(), $tz );
		$to   = $to && $to >= $from ? min( $to, gmdate( 'Y-m-d', strtotime( $from . ' +62 day UTC' ) ) ) : $from;
		$rows = Bookings::between( Clock::sql( Clock::local( $from, 0, $tz ) ), Clock::sql( Clock::local( $to, 1440, $tz ) ), array( 'pending_payment', 'pending', 'confirmed', 'completed', 'no_show', 'cancelled' ) );
		$out  = array();
		foreach ( $rows as $b ) {
			if ( ( $request['staff'] && (int) $request['staff'] !== (int) $b['staff_id'] ) || ( $request['service'] && (int) $request['service'] !== (int) $b['service_id'] ) ) {
				continue;
			}
			if ( 'cancelled' === $b['status'] && ! $request['cancelled'] ) {
				continue;
			}
			$out[] = Presenter::admin( $b );
		}
		return $this->respond(
			array(
				'from'     => $from,
				'to'       => $to,
				'tz'       => $tz->getName(),
				'bookings' => $out,
				'staff'    => array_map( array( Staff::class, 'public_view' ), Staff::all( true ) ),
				'hours'    => $this->hours_range(),
			)
		);
	}

	/**
	 * Earliest start / latest end across schedules (for the time grid).
	 *
	 * @return array{0:int,1:int} Hours.
	 */
	private function hours_range() {
		$lo = 24;
		$hi = 0;
		foreach ( Staff::all( true ) as $s ) {
			list( $week ) = Staff::week( $s );
			foreach ( $week as $list ) {
				foreach ( $list as $iv ) {
					$lo = min( $lo, (int) substr( $iv[0], 0, 2 ) );
					$hi = max( $hi, (int) ceil( Clock::minutes( $iv[1] ) / 60 ) );
				}
			}
		}
		return $lo < $hi ? array( max( 0, $lo - 1 ), min( 24, $hi + 1 ) ) : array( 8, 19 );
	}

	/**
	 * GET availability?service=&staff=&date=&days= (admin: ignores notice/advance).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function availability( \WP_REST_Request $request ) {
		$service = Services::find( (int) $request['service'] );
		if ( ! $service ) {
			return $this->respond( new \WP_Error( 'dev3bk_bad_service', __( 'Choose a service.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) ) );
		}
		$tz   = Settings::timezone();
		$date = Exceptions::date( (string) $request['date'] );
		$date = $date ? $date : Clock::date( Clock::now(), $tz );
		$days = min( 14, max( 1, (int) ( $request['days'] ? $request['days'] : 1 ) ) );
		$to   = gmdate( 'Y-m-d', strtotime( $date . ' +' . ( $days - 1 ) . ' day UTC' ) );
		$data = Engine::days(
			$service,
			(int) $request['staff'],
			$date,
			$to,
			$tz->getName(),
			array(
				'ignore_rules' => true,
				'exclude'      => (int) $request['exclude'],
				'now'          => Clock::now(),
			)
		);
		// Admins still may not book in the past.
		foreach ( $data['days'] as $d => $slots ) {
			$data['days'][ $d ] = array_values( array_filter( $slots, static fn( $s ) => Clock::parse( $s['start'] ) > Clock::now() ) );
		}
		return $this->respond( $data );
	}

	/**
	 * POST ai/nl { text, service, staff }: DEV3 AI "Find a slot" in the admin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_nl( \WP_REST_Request $request ) {
		return $this->respond(
			Nl_Booking::suggest(
				(string) $request['text'],
				array(
					'service_id' => (int) $request['service'],
					'staff_id'   => (int) $request['staff'],
					'tz'         => Settings::timezone()->getName(),
					'limit'      => 12,
				),
				'admin'
			)
		);
	}

	/**
	 * GET customers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function customers( \WP_REST_Request $request ) {
		return $this->respond(
			Customers::search(
				array(
					'search'   => sanitize_text_field( (string) $request['search'] ),
					'page'     => (int) $request['page'],
					'per_page' => $request['per_page'] ? (int) $request['per_page'] : 20,
				)
			)
		);
	}

	/**
	 * GET customers/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function customer( \WP_REST_Request $request ) {
		$c = Customers::get( (int) $request['id'] );
		if ( ! $c ) {
			return $this->respond( new \WP_Error( 'dev3bk_not_found', __( 'Customer not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) ) );
		}
		$c['stats']    = Customers::stats( $c['id'] );
		$c['bookings'] = array_map(
			array( Presenter::class, 'admin' ),
			Bookings::search(
				array(
					'customer' => $c['id'],
					'per_page' => 50,
				)
			)['rows']
		);
		return $this->respond( $c );
	}

	/**
	 * PATCH customers/<id> { name, phone, notes }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function customer_patch( \WP_REST_Request $request ) {
		$c = Customers::get( (int) $request['id'] );
		if ( ! $c ) {
			return $this->respond( new \WP_Error( 'dev3bk_not_found', __( 'Customer not found.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) ) );
		}
		$p    = $this->params( $request );
		$data = array();
		if ( isset( $p['name'] ) ) {
			$data['name'] = mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 190 );
		}
		if ( isset( $p['phone'] ) ) {
			$data['phone'] = mb_substr( preg_replace( '/[^0-9+()\-\s.]/', '', (string) $p['phone'] ), 0, 40 );
		}
		if ( isset( $p['notes'] ) ) {
			$data['notes'] = mb_substr( sanitize_textarea_field( (string) $p['notes'] ), 0, 2000 );
		}
		if ( $data ) {
			Customers::update( $c['id'], $data );
		}
		return $this->customer( $request );
	}

	/**
	 * GET templates.
	 *
	 * @return \WP_REST_Response
	 */
	public function templates() {
		return $this->respond(
			array(
				'templates'    => Templates::all(),
				'placeholders' => Templates::PLACEHOLDERS,
				'sample'       => Mailer::sample_vars(),
			)
		);
	}

	/**
	 * PUT templates/<tpl> { subject, body, enabled, reset, source }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template_save( \WP_REST_Request $request ) {
		return $this->respond( Templates::save( (string) $request['tpl'], $this->params( $request ) ) );
	}

	/**
	 * POST templates/<tpl>/ai { tone, length, language, instructions }: draft only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template_ai( \WP_REST_Request $request ) {
		return $this->respond( Writers::template( (string) $request['tpl'], $this->params( $request ) ) );
	}

	/**
	 * POST templates/<tpl>/preview { subject, body }: rendered HTML with sample values.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template_preview( \WP_REST_Request $request ) {
		$p    = $this->params( $request );
		$tpl  = array_intersect_key( $p, array_flip( array( 'subject', 'body' ) ) );
		$mail = Mailer::render( (string) $request['tpl'], Mailer::sample_vars(), array_map( 'strval', $tpl ) );
		return $mail ? $this->respond( $mail ) : $this->respond( new \WP_Error( 'dev3bk_not_found', __( 'Unknown template.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) ) );
	}

	/**
	 * POST templates/<tpl>/test { to }: sends the approved template with sample values.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template_test( \WP_REST_Request $request ) {
		$to = sanitize_email( (string) $request['to'] );
		$to = is_email( $to ) ? $to : wp_get_current_user()->user_email;
		$ok = Mailer::send( (string) $request['tpl'], $to, Mailer::sample_vars() );
		return $ok ? $this->respond( array( 'sent' => $to ) ) : $this->respond( new \WP_Error( 'dev3bk_mail_failed', __( 'The test email could not be sent (is the template turned on?).', 'dev3-ai-booking-appointments' ), array( 'status' => 500 ) ) );
	}

	/**
	 * GET payments/stripe.
	 *
	 * @return \WP_REST_Response
	 */
	public function stripe() {
		return $this->respond( Stripe::view() + array( 'woo_active' => class_exists( 'WooCommerce' ) ) );
	}

	/**
	 * PUT payments/stripe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stripe_save( \WP_REST_Request $request ) {
		$res = Stripe::save( $this->params( $request ) );
		return $this->respond( is_wp_error( $res ) ? $res : $res + array( 'woo_active' => class_exists( 'WooCommerce' ) ) );
	}

	/**
	 * POST pages: (re)creates the booking + account pages.
	 *
	 * @return \WP_REST_Response
	 */
	public function pages() {
		Pages::ensure( true );
		return $this->respond(
			array(
				'booking_page' => (int) Settings::get( 'booking_page' ),
				'account_page' => (int) Settings::get( 'account_page' ),
				'booking_url'  => (string) get_permalink( (int) Settings::get( 'booking_page' ) ),
				'account_url'  => (string) get_permalink( (int) Settings::get( 'account_page' ) ),
			)
		);
	}
}
