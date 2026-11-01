<?php
/**
 * Storefront: the booking widget ([dev3bk_booking] + "Booking form" block) and the customer area
 * ([dev3bk_my_bookings] + "My bookings" block). One small vanilla-JS bundle, loaded only where used.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Frontend;

use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Widget + blocks.
 */
class Widget {

	const HANDLE = 'dev3bk-front';

	/**
	 * Printed boot config once.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Hooks.
	 */
	public static function register() {
		add_shortcode( 'dev3bk_booking', array( self::class, 'shortcode' ) );
		add_shortcode( 'dev3bk_my_bookings', array( Account::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'blocks' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
	}

	/**
	 * Registers the storefront bundle (enqueued on render).
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$asset_file = DEV3BK_PATH . 'build/front.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		wp_register_script( self::HANDLE, DEV3BK_URL . 'build/front.js', array(), $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'dev3-ai-booking-appointments', DEV3BK_PATH . 'languages' );
		if ( is_readable( DEV3BK_PATH . 'build/front.css' ) ) {
			wp_register_style( self::HANDLE, DEV3BK_URL . 'build/front.css', array(), $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Enqueues the bundle + boot config.
	 */
	public static function enqueue() {
		self::register_assets();
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		if ( ! self::$booted ) {
			self::$booted = true;
			wp_add_inline_script( self::HANDLE, 'window.dev3bkFront = ' . wp_json_encode( self::config() ) . ';', 'before' );
		}
	}

	/**
	 * Boot config (no secrets).
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'restUrl'  => esc_url_raw( rest_url( 'dev3bk/v1/' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn' => is_user_logged_in(),
			'loginUrl' => wp_login_url( (string) get_permalink() ),
			'locale'   => str_replace( '_', '-', get_locale() ),
			'strings'  => Strings::all(),
		);
	}

	/**
	 * Accent style attribute.
	 *
	 * @return string
	 */
	public static function style() {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color', '#4f46e5' ) );
		return '--dev3bk-accent:' . ( $accent ? $accent : '#4f46e5' );
	}

	/**
	 * Widget markup.
	 *
	 * @param array $atts service, staff, category.
	 * @return string
	 */
	public static function render( array $atts ) {
		self::enqueue();
		$data = array(
			'service'  => absint( $atts['service'] ?? 0 ),
			'staff'    => absint( $atts['staff'] ?? 0 ),
			'category' => sanitize_text_field( (string) ( $atts['category'] ?? '' ) ),
		);
		$s    = Strings::all();
		return '<div class="dev3bk-widget" data-dev3bk="' . esc_attr( (string) wp_json_encode( $data ) ) . '" style="' . esc_attr( self::style() ) . '">'
			. '<div class="dev3bk-boot" aria-busy="true"><span class="dev3bk-spin" aria-hidden="true"></span><span>' . esc_html( $s['loading'] ) . '</span></div>'
			. '<noscript><p>' . esc_html__( 'Please enable JavaScript to book an appointment.', 'dev3-ai-booking-appointments' ) . '</p></noscript>'
			. '</div>';
	}

	/**
	 * [dev3bk_booking service="" staff="" category=""].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'service'  => 0,
				'staff'    => 0,
				'category' => '',
			),
			(array) $atts,
			'dev3bk_booking'
		);
		return self::render( $atts );
	}

	/**
	 * Gutenberg blocks (server-rendered).
	 */
	public static function blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$asset_file = DEV3BK_PATH . 'build/blocks.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = include $asset_file;
			wp_register_script( 'dev3bk-blocks', DEV3BK_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'dev3bk-blocks', 'dev3-ai-booking-appointments', DEV3BK_PATH . 'languages' );
		}
		register_block_type(
			'dev3bk/booking-form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'dev3bk-blocks',
				'render_callback' => static fn( $attrs ) => self::render( (array) $attrs ),
				'attributes'      => array(
					'service'  => array(
						'type'    => 'number',
						'default' => 0,
					),
					'staff'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'category' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
		register_block_type(
			'dev3bk/my-bookings',
			array(
				'api_version'     => 2,
				'editor_script'   => 'dev3bk-blocks',
				'render_callback' => static fn() => Account::render(),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide' ),
				),
			)
		);
	}
}
