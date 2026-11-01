<?php
/**
 * Payments: method resolution, Stripe Checkout (mocked HTTP + signed webhooks + refunds) and the
 * WooCommerce checkout flow.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Payments\Payments;
use Dev3\AiBooking\Payments\Stripe;
use Dev3\AiBooking\Payments\Woo;

class PaymentsTest extends Dev3bk_Base_Case {

	private function stripe_on() {
		Stripe::save(
			array(
				'publishable' => 'pk_test_abc123',
				'secret'      => 'sk_test_secret123',
				'webhook'     => 'whsec_hook123',
			)
		);
		$this->settings( array( 'stripe_enabled' => true ) );
	}

	private function fixture( array $svc = array() ) {
		$svc   = $this->service( $svc );
		$staff = $this->staff( array(), array( $svc['id'] ) );
		return array( $svc, $staff );
	}

	public function test_methods_follow_service_mode_and_settings() {
		list( $svc ) = $this->fixture();
		$this->assertSame( array( 'later' ), Payments::methods( $svc ), 'No gateway: pay later only' );
		$this->stripe_on();
		$this->assertSame( array( 'stripe', 'later' ), Payments::methods( $svc ) );
		$svc['payment_mode'] = 'required';
		$this->assertSame( array( 'stripe' ), Payments::methods( $svc ) );
		$svc['payment_mode'] = 'off';
		$this->assertSame( array( 'later' ), Payments::methods( $svc ) );
		$svc['price'] = 0;
		$this->assertSame( array( 'none' ), Payments::methods( $svc ) );
		$this->settings( array( 'woo_enabled' => true ) );
		$svc['price']        = 45;
		$svc['payment_mode'] = 'optional';
		$this->assertSame( array( 'stripe', 'woocommerce', 'later' ), Payments::methods( $svc ) );
	}

	public function test_keys_are_encrypted_and_masked() {
		$this->stripe_on();
		$raw = get_option( 'dev3bk_stripe' );
		$this->assertStringNotContainsString( 'sk_test_secret123', wp_json_encode( $raw ) );
		$view = Stripe::view();
		$this->assertTrue( $view['secret_set'] );
		$this->assertTrue( $view['test_mode'] );
		$this->assertStringNotContainsString( 'secret123', $view['secret_masked'] );
		$this->assertSame( 'sk_test_secret123', Stripe::secret() );
		$this->assertWPError( Stripe::save( array( 'secret' => 'not-a-key' ) ) );
		Stripe::save( array( 'secret' => $view['secret_masked'] ) ); // Masked value is ignored.
		$this->assertSame( 'sk_test_secret123', Stripe::secret() );
	}

	public function test_stripe_checkout_session_and_signed_webhook() {
		list( $svc, $staff ) = $this->fixture( array( 'deposit_percent' => 20 ) );
		$this->stripe_on();
		$this->http['api.stripe.com/v1/checkout/sessions'] = array(
			200,
			array(
				'id'  => 'cs_test_1',
				'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
			),
		);
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'payment' => 'stripe' ) );
		$this->assertSame( 'pending_payment', $b['status'] );
		$this->assertSame( 9.0, $b['amount_due'] );
		$this->assertEmpty( $this->mails, 'No confirmation before payment' );
		$next = Payments::next_step( $b );
		$this->assertSame( 'redirect', $next['type'] );
		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_1', $next['url'] );
		$req = $this->sent( 'checkout/sessions' )[0];
		parse_str( $req['body'], $form );
		$this->assertSame( '900', (string) $form['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'usd', $form['line_items'][0]['price_data']['currency'] );
		$this->assertSame( $b['ref'], $form['client_reference_id'] );
		$this->assertSame( 'cs_test_1', Bookings::fresh( $b['id'] )['stripe_session'] );

		$payload = wp_json_encode(
			array(
				'type' => 'checkout.session.completed',
				'data' => array(
					'object' => array(
						'id'                  => 'cs_test_1',
						'client_reference_id' => $b['ref'],
						'payment_status'      => 'paid',
						'amount_total'        => 900,
						'payment_intent'      => 'pi_123',
					),
				),
			)
		);
		$this->assertWPError( Stripe::webhook( $payload, 't=' . time() . ',v1=deadbeef' ), 'Bad signature rejected' );
		$t   = time();
		$sig = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_hook123' );
		$this->assertTrue( Stripe::webhook( $payload, $sig )['received'] );
		$paid = Bookings::fresh( $b['id'] );
		$this->assertSame( 'confirmed', $paid['status'] );
		$this->assertSame( 'paid', $paid['payment_status'] );
		$this->assertSame( 9.0, $paid['paid_amount'] );
		$this->assertSame( 'pi_123', $paid['stripe_intent'] );
		$this->assertStringContainsString( 'confirmed', $this->subjects()[0] );
		// Replays are idempotent.
		$count = count( $this->mails );
		Stripe::webhook( $payload, $sig );
		$this->assertCount( $count, $this->mails );
	}

	public function test_signature_tolerance() {
		$payload = '{"a":1}';
		$old     = time() - 600;
		$this->assertFalse( Stripe::verify_signature( $payload, 't=' . $old . ',v1=' . hash_hmac( 'sha256', $old . '.' . $payload, 'k' ), 'k' ) );
		$this->assertTrue( Stripe::verify_signature( $payload, 't=' . time() . ',v1=x,v1=' . hash_hmac( 'sha256', time() . '.' . $payload, 'k' ), 'k' ) );
	}

	public function test_verify_on_return_and_expired_session() {
		list( $svc, $staff ) = $this->fixture();
		$this->stripe_on();
		$this->http['checkout/sessions/cs_back'] = array(
			200,
			array(
				'id'                  => 'cs_back',
				'client_reference_id' => 'X',
				'payment_status'      => 'paid',
				'amount_total'        => 4500,
				'payment_intent'      => 'pi_9',
			),
		);
		$this->http['checkout/sessions']         = array(
			200,
			array(
				'id'  => 'cs_back',
				'url' => 'https://checkout.stripe.com/x',
			),
		);
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'payment' => 'stripe' ) );
		Payments::next_step( $b );
		$this->http['checkout/sessions/cs_back'][1]['client_reference_id'] = $b['ref'];
		$b = Stripe::verify( Bookings::fresh( $b['id'] ) );
		$this->assertSame( 'paid', $b['payment_status'] );
		$this->assertSame( 45.0, $b['paid_amount'] );

		$c = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 14:00' ), array( 'payment' => 'stripe' ) );
		Stripe::apply_session(
			array(
				'id'                  => '',
				'client_reference_id' => $c['ref'],
				'status'              => 'expired',
			)
		);
		$this->assertSame( 'expired', Bookings::fresh( $c['id'] )['status'] );
	}

	public function test_stripe_error_releases_the_slot() {
		list( $svc, $staff ) = $this->fixture();
		$this->stripe_on();
		$this->http['checkout/sessions'] = array( 400, array( 'error' => array( 'message' => 'Invalid currency' ) ) );
		$b                               = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'payment' => 'stripe' ) );
		$next                            = Payments::next_step( $b );
		$this->assertWPError( $next );
		$this->assertSame( 'Invalid currency', $next->get_error_message() );
		$this->assertSame( 'expired', Bookings::fresh( $b['id'] )['status'] );
	}

	public function test_customer_cancel_refunds_inside_window_only() {
		list( $svc, $staff ) = $this->fixture();
		$this->stripe_on();
		$this->http['api.stripe.com/v1/refunds'] = array(
			200,
			array(
				'id'     => 're_1',
				'status' => 'succeeded',
			),
		);
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-06 10:00' ), array( 'payment' => 'stripe' ) );
		$b = Manager::paid( $b, 45, 'stripe', array( 'stripe_intent' => 'pi_r' ) );
		$c = Manager::cancel( $b, 'Away', 'customer' );
		$this->assertSame( 'refunded', $c['payment_status'] );
		parse_str( $this->sent( 'v1/refunds' )[0]['body'], $form );
		$this->assertSame( 'pi_r', $form['payment_intent'] );
		$this->assertSame( '4500', (string) $form['amount'] );

		// Inside the refund window (but cancellations allowed up to 2 h): no refund.
		$this->settings(
			array(
				'cancel_cutoff_hours' => 2,
				'refund_window_hours' => 48,
			)
		);
		$this->requests = array();
		$d              = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-03 10:00' ), array( 'payment' => 'stripe' ) );
		$d              = Manager::paid( $d, 45, 'stripe', array( 'stripe_intent' => 'pi_s' ) );
		$d              = Manager::cancel( $d, '', 'customer' );
		$this->assertSame( 'paid', $d['payment_status'] );
		$this->assertEmpty( $this->sent( 'v1/refunds' ) );
	}

	public function test_woocommerce_order_flow() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		list( $svc, $staff ) = $this->fixture();
		$this->settings( array( 'woo_enabled' => true ) );
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'payment' => 'woocommerce' ) );
		$this->assertSame( 'pending_payment', $b['status'] );
		$pid = Woo::product_id();
		$this->assertSame( $pid, Woo::product_id(), 'One hidden product' );
		$this->assertSame( 'hidden', wc_get_product( $pid )->get_catalog_visibility() );

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $pid ) );
		$item->set_quantity( 1 );
		$item->set_total( 45 );
		Woo::line_item( $item, 'k', array( Woo::ITEM_KEY => $b['id'] ) );
		$order->add_item( $item );
		$order->set_billing_email( 'jamie@example.org' );
		$order->calculate_totals();
		$order->save();
		$this->assertSame( 'Haircut', $item->get_name() );
		$this->assertSame( $b['id'], (int) $item->get_meta( Woo::META ) );

		$this->mails = array();
		$order->payment_complete( 'txn_1' );
		$paid = Bookings::fresh( $b['id'] );
		$this->assertSame( 'confirmed', $paid['status'] );
		$this->assertSame( 'paid', $paid['payment_status'] );
		$this->assertSame( 'woocommerce', $paid['payment_method'] );
		$this->assertSame( $order->get_id(), $paid['wc_order_id'] );
		$this->assertSame( 45.0, $paid['paid_amount'] );

		// A second, unpaid order that gets cancelled releases its booking.
		$c      = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 14:00' ), array( 'payment' => 'woocommerce' ) );
		$order2 = wc_create_order();
		$item2  = new WC_Order_Item_Product();
		$item2->set_product( wc_get_product( $pid ) );
		$item2->set_quantity( 1 );
		Woo::line_item( $item2, 'k2', array( Woo::ITEM_KEY => $c['id'] ) );
		$order2->add_item( $item2 );
		$order2->save();
		$order2->update_status( 'on-hold' );
		$this->assertSame( 'pending', Bookings::fresh( $c['id'] )['status'], 'On-hold keeps the slot' );
		$order2->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', Bookings::fresh( $c['id'] )['status'] );
	}

	public function test_woocommerce_cart_add() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		list( $svc, $staff ) = $this->fixture();
		$this->settings( array( 'woo_enabled' => true ) );
		$b   = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'payment' => 'woocommerce' ) );
		$url = Woo::add_to_cart( $b );
		$this->assertIsString( $url );
		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$this->assertSame( $b['id'], $items[0][ Woo::ITEM_KEY ] );
		WC()->cart->calculate_totals();
		$this->assertEquals( 45, WC()->cart->get_subtotal() );
		$data = Woo::item_data( array(), $items[0] );
		$this->assertSame( $b['ref'], end( $data )['value'] );
		WC()->cart->empty_cart();
	}
}
