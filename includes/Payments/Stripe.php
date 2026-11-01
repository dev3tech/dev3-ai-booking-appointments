<?php
/**
 * Stripe Checkout (hosted page): sessions, verification on return, signed webhooks and refunds.
 * Keys live in their own option, encrypted with the core AES-256-GCM helper.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Payments;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Core3\Core\Encryption;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Data\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe.
 */
class Stripe {

	const OPTION = 'dev3bk_stripe';

	const API = 'https://api.stripe.com/v1/';

	const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Raw stored keys.
	 *
	 * @return array
	 */
	private static function stored() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v + array(
			'publishable' => '',
			'secret'      => '',
			'webhook'     => '',
		) : array(
			'publishable' => '',
			'secret'      => '',
			'webhook'     => '',
		);
	}

	/**
	 * Decrypted secret key.
	 *
	 * @return string
	 */
	public static function secret() {
		if ( defined( 'DEV3BK_STRIPE_SECRET' ) && constant( 'DEV3BK_STRIPE_SECRET' ) ) {
			return (string) constant( 'DEV3BK_STRIPE_SECRET' );
		}
		return Encryption::decrypt( (string) self::stored()['secret'] );
	}

	/**
	 * Decrypted webhook signing secret.
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return Encryption::decrypt( (string) self::stored()['webhook'] );
	}

	/**
	 * Has a secret key?
	 *
	 * @return bool
	 */
	public static function ready() {
		return '' !== self::secret();
	}

	/**
	 * Masked view for the admin.
	 *
	 * @return array
	 */
	public static function view() {
		$secret = self::secret();
		$hook   = self::webhook_secret();
		return array(
			'publishable'    => (string) self::stored()['publishable'],
			'secret_masked'  => '' === $secret ? '' : substr( $secret, 0, 8 ) . str_repeat( '•', 6 ) . substr( $secret, -4 ),
			'secret_set'     => '' !== $secret,
			'webhook_set'    => '' !== $hook,
			'test_mode'      => 0 === strpos( $secret, 'sk_test_' ),
			'webhook_url'    => rest_url( 'dev3bk/v1/stripe/webhook' ),
			'webhook_events' => array( 'checkout.session.completed', 'checkout.session.expired', 'charge.refunded' ),
		);
	}

	/**
	 * Saves keys (masked values are ignored).
	 *
	 * @param array $in publishable, secret, webhook.
	 * @return array|\WP_Error View.
	 */
	public static function save( array $in ) {
		$row = self::stored();
		if ( isset( $in['publishable'] ) ) {
			$pk = trim( sanitize_text_field( (string) $in['publishable'] ) );
			if ( '' !== $pk && ! preg_match( '/^pk_(test|live)_[A-Za-z0-9]+$/', $pk ) ) {
				return new \WP_Error( 'dev3bk_invalid', __( 'The publishable key should start with pk_test_ or pk_live_.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$row['publishable'] = $pk;
		}
		foreach ( array(
			'secret'  => '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/',
			'webhook' => '/^whsec_[A-Za-z0-9]+$/',
		) as $key => $pattern ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $in[ $key ] ) );
			if ( false !== strpos( $value, '•' ) ) {
				continue;
			}
			if ( '' !== $value && ! preg_match( $pattern, $value ) ) {
				return new \WP_Error( 'dev3bk_invalid', 'secret' === $key ? __( 'The secret key should start with sk_test_ or sk_live_.', 'dev3-ai-booking-appointments' ) : __( 'The webhook secret should start with whsec_.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
			}
			$row[ $key ] = '' === $value ? '' : Encryption::encrypt( $value );
		}
		update_option( self::OPTION, $row, false );
		return self::view();
	}

	/**
	 * Amount in the smallest currency unit.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	public static function minor( $amount, $currency ) {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}

	/**
	 * Calls the Stripe API.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Path.
	 * @param array  $params Params.
	 * @param string $idem   Idempotency key.
	 * @return array|\WP_Error
	 */
	public static function api( $method, $path, array $params = array(), $idem = '' ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'dev3bk_stripe_keys', __( 'Stripe is not connected. Add your secret key in Settings → Payments.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$url  = self::API . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( $idem ) {
			$args['headers']['Idempotency-Key'] = $idem;
		}
		if ( 'GET' === $method ) {
			$url = $params ? $url . '?' . http_build_query( $params ) : $url;
		} else {
			$args['body'] = http_build_query( $params );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'dev3bk_stripe_http', __( 'Could not reach Stripe. Please try again.', 'dev3-ai-booking-appointments' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Stripe returned an error.', 'dev3-ai-booking-appointments' );
			return new \WP_Error( 'dev3bk_stripe_error', $msg, array( 'status' => 502 ) );
		}
		return $body;
	}

	/**
	 * Creates a Checkout Session for the amount due; returns its URL.
	 *
	 * @param array $b Booking.
	 * @return string|\WP_Error
	 */
	public static function create_session( array $b ) {
		$service  = Services::get( $b['service_id'] );
		$customer = Customers::get( $b['customer_id'] );
		$view     = Presenter::base( $b );
		$name     = ( $service ? $service['name'] : __( 'Appointment', 'dev3-ai-booking-appointments' ) );
		$desc     = $view['day_label'] . ', ' . $view['date_label'] . ' · ' . $view['time_label'] . ( $view['staff'] ? ' · ' . $view['staff'] : '' );
		if ( $b['amount_due'] < $b['amount'] ) {
			/* translators: %s: name. */
			$name = sprintf( __( 'Deposit: %s', 'dev3-ai-booking-appointments' ), $name );
		}
		$params = array(
			'mode'                => 'payment',
			'client_reference_id' => $b['ref'],
			'success_url'         => add_query_arg( 'session_id', '{CHECKOUT_SESSION_ID}', Payments::return_url( $b ) ),
			'cancel_url'          => add_query_arg( 'dev3bk_cancelled', '1', Presenter::manage_url( $b ) ),
			'expires_at'          => Clock::now() + 31 * MINUTE_IN_SECONDS,
			'metadata'            => array( 'dev3bk_ref' => $b['ref'] ),
			'payment_intent_data' => array( 'metadata' => array( 'dev3bk_ref' => $b['ref'] ) ),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $b['currency'] ),
						'unit_amount'  => self::minor( $b['amount_due'], $b['currency'] ),
						'product_data' => array(
							'name'        => mb_substr( $name, 0, 200 ),
							'description' => mb_substr( $desc, 0, 300 ),
						),
					),
				),
			),
		);
		if ( $customer && is_email( $customer['email'] ) ) {
			$params['customer_email'] = $customer['email'];
		}
		$session = self::api( 'POST', 'checkout/sessions', $params, 'dev3bk-' . $b['ref'] . '-' . $b['sequence'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['url'] ) || empty( $session['id'] ) ) {
			return new \WP_Error( 'dev3bk_stripe_error', __( 'Stripe did not return a checkout page.', 'dev3-ai-booking-appointments' ), array( 'status' => 502 ) );
		}
		Bookings::update( $b['id'], array( 'stripe_session' => (string) $session['id'] ) );
		Events::log( $b['id'], 'payment', __( 'Stripe checkout started.', 'dev3-ai-booking-appointments' ) );
		return esc_url_raw( (string) $session['url'] );
	}

	/**
	 * Re-checks the booking's session after the customer returns.
	 *
	 * @param array $b Booking.
	 * @return array Booking (fresh).
	 */
	public static function verify( array $b ) {
		if ( 'stripe' !== $b['payment_method'] || '' === $b['stripe_session'] || 'paid' === $b['payment_status'] ) {
			return $b;
		}
		$session = self::api( 'GET', 'checkout/sessions/' . rawurlencode( $b['stripe_session'] ) );
		if ( ! is_wp_error( $session ) ) {
			self::apply_session( $session );
		}
		return Bookings::fresh( $b['id'] );
	}

	/**
	 * Applies a completed/expired session to its booking.
	 *
	 * @param array $session Session object.
	 * @return array|null Booking.
	 */
	public static function apply_session( array $session ) {
		$ref = (string) ( $session['client_reference_id'] ?? ( $session['metadata']['dev3bk_ref'] ?? '' ) );
		$b   = Bookings::by_ref( $ref );
		if ( ! $b || ( $b['stripe_session'] && ( $session['id'] ?? '' ) !== $b['stripe_session'] ) ) {
			return null;
		}
		if ( 'paid' === ( $session['payment_status'] ?? '' ) ) {
			$amount = isset( $session['amount_total'] ) ? (float) $session['amount_total'] : self::minor( $b['amount_due'], $b['currency'] );
			$amount = in_array( strtoupper( $b['currency'] ), self::ZERO_DECIMAL, true ) ? $amount : $amount / 100;
			return Manager::paid( $b, $amount, 'stripe', array( 'stripe_intent' => (string) ( $session['payment_intent'] ?? '' ) ) );
		}
		if ( 'expired' === ( $session['status'] ?? '' ) && 'pending_payment' === $b['status'] ) {
			Bookings::update( $b['id'], array( 'status' => 'expired' ) );
			Events::log( $b['id'], 'expired', __( 'Stripe checkout expired; the time was released.', 'dev3-ai-booking-appointments' ) );
		}
		return Bookings::fresh( $b['id'] );
	}

	/**
	 * Verifies a Stripe-Signature header (v1 HMAC-SHA256, 5-minute tolerance).
	 *
	 * @param string $payload Raw body.
	 * @param string $header  Header.
	 * @param string $secret  Signing secret.
	 * @param int    $now     Unix.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $now = 0 ) {
		$now = $now ? $now : time();
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$t   = 0;
		$sig = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( ! $t || ! $sig || abs( $now - $t ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handles a webhook event.
	 *
	 * @param string $payload Raw body.
	 * @param string $header  Stripe-Signature.
	 * @return array|\WP_Error
	 */
	public static function webhook( $payload, $header ) {
		if ( ! self::verify_signature( $payload, $header, self::webhook_secret() ) ) {
			return new \WP_Error( 'dev3bk_bad_signature', __( 'Invalid signature.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		$type  = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
		$obj   = is_array( $event ) && isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired' ), true ) ) {
			self::apply_session( $obj );
		} elseif ( 'charge.refunded' === $type ) {
			$ref = (string) ( $obj['metadata']['dev3bk_ref'] ?? '' );
			$b   = $ref ? Bookings::by_ref( $ref ) : null;
			if ( $b && 'refunded' !== $b['payment_status'] ) {
				Bookings::update( $b['id'], array( 'payment_status' => 'refunded' ) );
				Events::log( $b['id'], 'refund', __( 'Refund confirmed by Stripe.', 'dev3-ai-booking-appointments' ) );
			}
		}
		return array(
			'received' => true,
			'type'     => $type,
		);
	}

	/**
	 * Refunds through Stripe.
	 *
	 * @param array $b      Booking.
	 * @param float $amount Amount.
	 * @return true|\WP_Error
	 */
	public static function refund( array $b, $amount ) {
		if ( '' === $b['stripe_intent'] ) {
			return new \WP_Error( 'dev3bk_stripe_no_payment', __( 'No Stripe payment found for this booking.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$res = self::api(
			'POST',
			'refunds',
			array(
				'payment_intent' => $b['stripe_intent'],
				'amount'         => self::minor( $amount, $b['currency'] ),
				'metadata'       => array( 'dev3bk_ref' => $b['ref'] ),
			),
			'dev3bk-refund-' . $b['ref'] . '-' . self::minor( $amount, $b['currency'] )
		);
		return is_wp_error( $res ) ? $res : true;
	}
}
