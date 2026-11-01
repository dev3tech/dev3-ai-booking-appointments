<?php
/**
 * WooCommerce checkout payments: a hidden virtual "Appointment" product carries the booking through
 * the cart (classic + block), the order line item keeps the booking id, and order status changes
 * confirm, hold, release or refund the booking. HPOS-safe (order CRUD only).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Payments;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Frontend\Account;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
class Woo {

	const PRODUCT_OPTION = 'dev3bk_woo_product';

	const ITEM_KEY = 'dev3bk_booking';

	const META = '_dev3bk_booking_id';

	/**
	 * Hooks (only when WooCommerce is active).
	 */
	public static function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( self::class, 'cart_item_name' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( self::class, 'cart_item_quantity' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( self::class, 'quantity_editable' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'line_item' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'order_paid' ) );
		add_action( 'woocommerce_order_status_on-hold', array( self::class, 'order_on_hold' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'order_released' ) );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'order_released' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'order_refunded' ) );
		add_filter( 'woocommerce_order_item_display_meta_key', array( self::class, 'meta_label' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( self::class, 'hidden_meta' ) );
		// My Account → Appointments.
		add_filter( 'woocommerce_get_query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'account_menu' ) );
		add_action( 'woocommerce_account_appointments_endpoint', array( self::class, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_appointments_title', array( self::class, 'account_title' ) );
	}

	/**
	 * The hidden "Appointment" product id (created on demand).
	 *
	 * @return int
	 */
	public static function product_id() {
		$id      = (int) get_option( self::PRODUCT_OPTION, 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && 'trash' !== $product->get_status() ) {
			return $id;
		}
		$product = new \WC_Product_Simple();
		$product->set_name( __( 'Appointment', 'dev3-ai-booking-appointments' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( '0' );
		$product->set_tax_status( 'taxable' );
		$product->update_meta_data( '_dev3bk_product', 'yes' );
		$id = (int) $product->save();
		update_option( self::PRODUCT_OPTION, $id, false );
		return $id;
	}

	/**
	 * Puts the booking in the cart; returns the checkout URL.
	 *
	 * @param array $b Booking.
	 * @return string|\WP_Error
	 */
	public static function add_to_cart( array $b ) {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'dev3bk_woo_missing', __( 'WooCommerce is not active.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'wc_load_cart' ) && ( null === WC()->cart || null === WC()->session ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return new \WP_Error( 'dev3bk_woo_cart', __( 'The cart is not available.', 'dev3-ai-booking-appointments' ), array( 'status' => 500 ) );
		}
		WC()->cart->get_cart(); // Load the session cart first or the add is overwritten.
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( isset( $item[ self::ITEM_KEY ] ) ) {
				$other = Bookings::get( (int) $item[ self::ITEM_KEY ] );
				if ( ! $other || 'pending_payment' !== $other['status'] || (int) $other['id'] === (int) $b['id'] ) {
					WC()->cart->remove_cart_item( $key );
				}
			}
		}
		$key = WC()->cart->add_to_cart( self::product_id(), 1, 0, array(), array( self::ITEM_KEY => (int) $b['id'] ) );
		if ( ! $key ) {
			return new \WP_Error( 'dev3bk_woo_cart', __( 'Could not add the appointment to the cart.', 'dev3-ai-booking-appointments' ), array( 'status' => 500 ) );
		}
		if ( WC()->session ) {
			WC()->cart->set_session();
			WC()->session->save_data();
		}
		Events::log( $b['id'], 'payment', __( 'Added to the WooCommerce cart for checkout.', 'dev3-ai-booking-appointments' ) );
		return (string) wc_get_checkout_url();
	}

	/**
	 * Booking of a cart item (still holding its slot) or null.
	 *
	 * @param array $item Cart item.
	 * @return array|null
	 */
	private static function cart_booking( array $item ) {
		if ( empty( $item[ self::ITEM_KEY ] ) ) {
			return null;
		}
		$b = Bookings::get( (int) $item[ self::ITEM_KEY ] );
		return $b && 'pending_payment' === $b['status'] && ! Bookings::hold_expired( $b, Clock::now() ) ? $b : null;
	}

	/**
	 * Sets each appointment line's price from its booking.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ) {
		if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::ITEM_KEY ] ) || empty( $item['data'] ) ) {
				continue;
			}
			$b = Bookings::get( (int) $item[ self::ITEM_KEY ] );
			if ( $b ) {
				$item['data']->set_price( (float) $b['amount_due'] );
				$item['data']->set_name( self::line_name( $b ) );
			}
		}
	}

	/**
	 * "Service · date time" name.
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function line_name( array $b ) {
		$v = Presenter::base( $b );
		$n = $v['service'];
		if ( $b['amount_due'] < $b['amount'] ) {
			/* translators: %s: service name. */
			$n = sprintf( __( '%s (deposit)', 'dev3-ai-booking-appointments' ), $n );
		}
		return $n;
	}

	/**
	 * Cart/checkout item data (classic + block cart through the Store API).
	 *
	 * @param array $data Data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( empty( $item[ self::ITEM_KEY ] ) ) {
			return $data;
		}
		$b = Bookings::get( (int) $item[ self::ITEM_KEY ] );
		if ( ! $b ) {
			return $data;
		}
		$v      = Presenter::base( $b );
		$data[] = array(
			'key'   => __( 'When', 'dev3-ai-booking-appointments' ),
			'value' => $v['day_label'] . ', ' . $v['date_label'] . ' · ' . $v['time_label'],
		);
		if ( $v['staff'] ) {
			$data[] = array(
				'key'   => __( 'With', 'dev3-ai-booking-appointments' ),
				'value' => $v['staff'],
			);
		}
		$data[] = array(
			'key'   => __( 'Booking', 'dev3-ai-booking-appointments' ),
			'value' => $b['ref'],
		);
		return $data;
	}

	/**
	 * Cart item name.
	 *
	 * @param string $name Name.
	 * @param array  $item Item.
	 * @return string
	 */
	public static function cart_item_name( $name, $item ) {
		if ( empty( $item[ self::ITEM_KEY ] ) ) {
			return $name;
		}
		$b = Bookings::get( (int) $item[ self::ITEM_KEY ] );
		return $b ? esc_html( self::line_name( $b ) ) : $name;
	}

	/**
	 * Fixed quantity 1 (classic cart).
	 *
	 * @param string $html Quantity input.
	 * @param string $key  Cart key.
	 * @param array  $item Item.
	 * @return string
	 */
	public static function cart_item_quantity( $html, $key, $item = array() ) {
		unset( $key );
		return ! empty( $item[ self::ITEM_KEY ] ) ? '1' : $html;
	}

	/**
	 * Fixed quantity (block cart).
	 *
	 * @param bool        $editable Editable.
	 * @param \WC_Product $product  Product.
	 * @param array       $item     Item.
	 * @return bool
	 */
	public static function quantity_editable( $editable, $product = null, $item = array() ) {
		unset( $product );
		return ! empty( $item[ self::ITEM_KEY ] ) ? false : $editable;
	}

	/**
	 * Removes appointments whose hold expired or that were cancelled.
	 */
	public static function check_cart() {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item[ self::ITEM_KEY ] ) && ! self::cart_booking( $item ) ) {
				$b = Bookings::get( (int) $item[ self::ITEM_KEY ] );
				if ( $b && 'pending_payment' === $b['status'] ) {
					Bookings::update( $b['id'], array( 'status' => 'expired' ) );
				}
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'Your appointment hold expired, so it was removed from the cart. Please book a new time.', 'dev3-ai-booking-appointments' ), 'error' );
			}
		}
	}

	/**
	 * Copies the booking id onto the order line item.
	 *
	 * @param \WC_Order_Item_Product $item   Item.
	 * @param string                 $key    Cart key.
	 * @param array                  $values Cart values.
	 */
	public static function line_item( $item, $key, $values ) {
		unset( $key );
		if ( empty( $values[ self::ITEM_KEY ] ) ) {
			return;
		}
		$b = Bookings::get( (int) $values[ self::ITEM_KEY ] );
		if ( ! $b ) {
			return;
		}
		$v = Presenter::base( $b );
		$item->set_name( self::line_name( $b ) );
		$item->add_meta_data( self::META, (int) $b['id'], true );
		$item->add_meta_data( __( 'When', 'dev3-ai-booking-appointments' ), $v['day_label'] . ', ' . $v['date_label'] . ' · ' . $v['time_label'] . ' (' . str_replace( '_', ' ', $v['tz'] ) . ')', true );
		if ( $v['staff'] ) {
			$item->add_meta_data( __( 'With', 'dev3-ai-booking-appointments' ), $v['staff'], true );
		}
		$item->add_meta_data( __( 'Booking', 'dev3-ai-booking-appointments' ), $b['ref'], true );
	}

	/**
	 * Bookings on an order: [ [ booking, item ], … ].
	 *
	 * @param int|\WC_Order $order Order.
	 * @return array
	 */
	public static function order_bookings( $order ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		$out   = array();
		if ( ! $order ) {
			return $out;
		}
		foreach ( $order->get_items() as $item ) {
			$id = (int) $item->get_meta( self::META );
			$b  = $id ? Bookings::get( $id ) : null;
			if ( $b ) {
				$out[] = array( $b, $item );
			}
		}
		return $out;
	}

	/**
	 * Paid order → booking paid + confirmed.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		foreach ( self::order_bookings( $order ) as list( $b, $item ) ) {
			if ( in_array( $b['status'], array( 'cancelled' ), true ) ) {
				continue;
			}
			$amount = (float) $item->get_total() + (float) $item->get_total_tax();
			Manager::paid( $b, $amount, 'woocommerce', array( 'wc_order_id' => (int) $order->get_id() ) );
		}
	}

	/**
	 * On-hold (bank transfer, cheque): keep the slot as "pending approval", unpaid.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_on_hold( $order_id ) {
		foreach ( self::order_bookings( $order_id ) as list( $b ) ) {
			if ( 'pending_payment' === $b['status'] || 'expired' === $b['status'] ) {
				Bookings::update(
					$b['id'],
					array(
						'status'      => 'pending',
						'wc_order_id' => (int) $order_id,
					)
				);
				/* translators: %d: order id. */
				Events::log( $b['id'], 'payment', sprintf( __( 'Order #%d is on hold; the time is kept until payment arrives.', 'dev3-ai-booking-appointments' ), (int) $order_id ) );
			}
		}
	}

	/**
	 * Cancelled/failed order → release unpaid bookings.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_released( $order_id ) {
		foreach ( self::order_bookings( $order_id ) as list( $b ) ) {
			if ( 'paid' === $b['payment_status'] ) {
				continue;
			}
			if ( in_array( $b['status'], array( 'pending_payment', 'pending' ), true ) ) {
				Bookings::update(
					$b['id'],
					array(
						'status'       => 'cancelled',
						'cancelled_at' => Clock::sql( Clock::now() ),
						'wc_order_id'  => (int) $order_id,
					)
				);
				/* translators: %d: order id. */
				Events::log( $b['id'], 'cancelled', sprintf( __( 'Order #%d was cancelled or failed; the time was released.', 'dev3-ai-booking-appointments' ), (int) $order_id ) );
			}
		}
	}

	/**
	 * Fully refunded order → booking refunded.
	 *
	 * @param int $order_id Order.
	 */
	public static function order_refunded( $order_id ) {
		foreach ( self::order_bookings( $order_id ) as list( $b ) ) {
			if ( 'refunded' !== $b['payment_status'] ) {
				Bookings::update(
					$b['id'],
					array(
						'payment_status' => 'refunded',
						'paid_amount'    => 0,
					)
				);
				Events::log( $b['id'], 'refund', __( 'The WooCommerce order was refunded.', 'dev3-ai-booking-appointments' ) );
			}
		}
	}

	/**
	 * Creates a WooCommerce refund for the booking line (through the gateway when it supports refunds).
	 *
	 * @param array $b      Booking.
	 * @param float $amount Amount.
	 * @return true|\WP_Error
	 */
	public static function refund( array $b, $amount ) {
		$order = $b['wc_order_id'] ? wc_get_order( $b['wc_order_id'] ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'dev3bk_woo_no_order', __( 'No WooCommerce order found for this booking.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$amount  = min( (float) $amount, (float) $order->get_remaining_refund_amount() );
		$gateway = function_exists( 'wc_get_payment_gateway_by_order' ) ? wc_get_payment_gateway_by_order( $order ) : null;
		$refund  = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				/* translators: %s: booking reference. */
				'reason'         => sprintf( __( 'Booking %s cancelled', 'dev3-ai-booking-appointments' ), $b['ref'] ),
				'refund_payment' => $gateway && $gateway->supports( 'refunds' ),
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		return true;
	}

	/**
	 * Hides the internal meta key.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public static function hidden_meta( $keys ) {
		$keys[] = self::META;
		return $keys;
	}

	/**
	 * Passes display keys through (they are already translated labels).
	 *
	 * @param string $key  Key.
	 * @param object $meta Meta.
	 * @return string
	 */
	public static function meta_label( $key, $meta = null ) {
		unset( $meta );
		return $key;
	}

	/**
	 * My Account endpoint.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars['appointments'] = 'appointments';
		return $vars;
	}

	/**
	 * My Account menu.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function account_menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out['appointments'] = __( 'Appointments', 'dev3-ai-booking-appointments' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['appointments'] ) ) {
			$out['appointments'] = __( 'Appointments', 'dev3-ai-booking-appointments' );
		}
		return $out;
	}

	/**
	 * My Account page content.
	 */
	public static function account_page() {
		echo Account::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped markup.
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public static function account_title() {
		return __( 'Appointments', 'dev3-ai-booking-appointments' );
	}

	/**
	 * Is the WooCommerce setting on (for callers outside this class)?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'woo_enabled' );
	}
}
