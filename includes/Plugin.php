<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking;

use Dev3\AiBooking\Core3\Core\Assets;
use Dev3\AiBooking\Core3\Core\Privacy;
use Dev3\AiBooking\Core3\Core\Schema;
use Dev3\AiBooking\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3bk_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation: tables, pages, cron.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3BK_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		Frontend\Pages::ensure();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Notify\Reminders::HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3bk_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3bk_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3bk_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'init' ), 1 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3BK_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		Notify\Reminders::register();
		Payments\Woo::register();
		Frontend\Widget::register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Init: translations, prompts, privacy, assets.
	 */
	public function init() {
		load_plugin_textdomain( 'dev3-ai-booking-appointments', false, dirname( plugin_basename( DEV3BK_FILE ) ) . '/languages' );
		Ai\Prompts_Library::register();
		Privacy::init();
		Data\Personal_Data::register();
		static $assets = false;
		if ( ! $assets ) {
			$assets = true;
			$tz     = Settings::timezone();
			Assets::register(
				array( 'admin' ),
				array(
					'tz'         => $tz->getName(),
					'today'      => Availability\Clock::date( Availability\Clock::now(), $tz ),
					'timeFormat' => (string) Settings::get( 'time_format', '24' ),
					'weekStart'  => (int) Settings::get( 'week_start', 1 ),
					'currency'   => Settings::currency(),
					'wooActive'  => class_exists( 'WooCommerce' ),
					'locale'     => str_replace( '_', '-', get_user_locale() ),
					'siteUrl'    => home_url( '/' ),
				)
			);
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
