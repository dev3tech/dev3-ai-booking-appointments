<?php
/**
 * Shared set-up: clean plugin tables (DELETE), settings reset, frozen clock, fake HTTP (Gemini +
 * Stripe) and mail capture, plus fixture helpers.
 *
 * @package Dev3\AiBooking
 */

use Dev3\AiBooking\Ai\Prompts_Library;
use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Core3\Api\Ai;
use Dev3\AiBooking\Core3\Core\Cache;
use Dev3\AiBooking\Core3\Core\Schema;
use Dev3\AiBooking\Core3\Core\Settings_Store;
use Dev3\AiBooking\Core3\Data\Usage_Repository;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Exceptions;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Data\Tables;
use Dev3\AiBooking\Notify\Templates;

abstract class Dev3bk_Base_Case extends WP_UnitTestCase {

	/**
	 * Frozen "now" (unix).
	 *
	 * @var int
	 */
	protected $now = 0;

	/**
	 * Captured HTTP requests: [ url, method, body (raw), json ].
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies: url substring => [ status, body ] or callable( url, args ).
	 *
	 * @var array
	 */
	protected $http = array();

	/**
	 * Captured mails.
	 *
	 * @var array
	 */
	protected $mails = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3BK_DB_VERSION );
		global $wpdb;
		foreach ( Tables::NAMES as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore
		}
		delete_option( Templates::OPTION );
		delete_option( 'dev3bk_stripe' );
		update_option( Settings_Store::OPTION, array( 'timezone' => 'Europe/London' ) );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Cache::flush();
		Prompts_Library::register();
		Bookings::forget();
		Staff::forget();
		Exceptions::forget();
		Ai::$client     = null;
		$this->requests = array();
		$this->http     = array();
		$this->mails    = array();
		$this->now      = strtotime( '2026-11-02 08:00:00 UTC' ); // Monday.
		add_filter( 'dev3bk_now', array( $this, 'clock' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3bk_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3bk_public_rate_limit', array( $this, 'no_limit' ) );
		add_filter( 'dev3bk_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'dev3bk_now', array( $this, 'clock' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3bk_public_rate_limit', array( $this, 'no_limit' ) );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function clock() {
		return $this->now;
	}

	public function no_limit() {
		return 10000;
	}

	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	protected function subjects() {
		return array_map( static fn( $m ) => (string) $m['subject'], $this->mails );
	}

	protected function settings( array $settings ) {
		Settings_Store::update( $settings );
		Settings_Store::flush();
	}

	protected function ai_on( array $settings = array() ) {
		$this->settings( array( 'api_key' => 'AIza-test-key-000000000000' ) + $settings );
	}

	/**
	 * Gemini reply with structured JSON.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 400,
					'candidatesTokenCount' => 80,
					'totalTokenCount'      => 480,
				),
			),
		);
	}

	public function fake_http( $pre, $args, $url ) {
		$raw              = isset( $args['body'] ) ? $args['body'] : '';
		$this->requests[] = array(
			'url'    => $url,
			'method' => $args['method'] ?? 'GET',
			'body'   => $raw,
			'json'   => is_string( $raw ) ? json_decode( $raw, true ) : null,
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $args );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	protected function sent( $needle ) {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $needle ) ) );
	}

	/**
	 * Service fixture.
	 *
	 * @param array $args Fields.
	 * @return array
	 */
	protected function service( array $args = array() ) {
		$s = Services::save(
			$args + array(
				'name'          => 'Haircut',
				'duration'      => 60,
				'price'         => 45,
				'slot_interval' => 30,
				'min_notice'    => 0,
				'max_advance'   => 90,
				'payment_mode'  => 'optional',
			)
		);
		$this->assertIsArray( $s );
		return $s;
	}

	/**
	 * Staff fixture (own hours Mon–Fri 09–17 unless given), linked to services.
	 *
	 * @param array $args     Fields.
	 * @param array $services Service ids.
	 * @return array
	 */
	protected function staff( array $args = array(), array $services = array() ) {
		$week = array();
		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri' ) as $d ) {
			$week[ $d ] = array( array( '09:00', '17:00' ) );
		}
		$s = Staff::save(
			$args + array(
				'name'               => 'Anna Berg',
				'email'              => 'anna@example.org',
				'use_business_hours' => false,
				'hours'              => $week,
				'services'           => $services,
			)
		);
		$this->assertIsArray( $s );
		return $s;
	}

	/**
	 * UTC unix for a local wall time.
	 *
	 * @param string $local "Y-m-d H:i".
	 * @param string $tz    Zone.
	 * @return int
	 */
	protected function local_ts( $local, $tz = 'Europe/London' ) {
		return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp();
	}

	/**
	 * Local "H:i" labels of slots on one staff-local date.
	 *
	 * @param array  $slots Engine slots.
	 * @param string $tz    Zone.
	 * @return string[]
	 */
	protected function labels( array $slots, $tz = 'Europe/London' ) {
		return array_map( static fn( $s ) => Clock::format( $s['start'], new DateTimeZone( $tz ), 'H:i' ), $slots );
	}

	/**
	 * Books directly through the manager.
	 *
	 * @param array $service Service.
	 * @param int   $staff   Staff id.
	 * @param int   $ts      Start.
	 * @param array $extra   Extra input.
	 * @return array|WP_Error
	 */
	protected function book( array $service, $staff, $ts, array $extra = array() ) {
		return Manager::create(
			$extra + array(
				'service_id' => $service['id'],
				'staff_id'   => $staff,
				'start'      => $ts,
				'name'       => 'Jamie Rivera',
				'email'      => 'jamie@example.org',
				'phone'      => '+44 20 7946 0000',
				'tz'         => 'Europe/London',
				'payment'    => 'later',
			)
		);
	}

	/**
	 * Admin user with the REST nonce.
	 *
	 * @return int
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * REST request helper.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below dev3bk/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send the REST nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $path, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3bk/v1/' . ltrim( $path, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $req );
	}
}
