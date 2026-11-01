<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder;

use Dev3\AiFormBuilder\AI\Entry_Insights;
use Dev3\AiFormBuilder\AI\Prompt_Library;
use Dev3\AiFormBuilder\Core3\Core\Assets;
use Dev3\AiFormBuilder\Core3\Core\Jobs;
use Dev3\AiFormBuilder\Core3\Core\Privacy;
use Dev3\AiFormBuilder\Core3\Core\Schema;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Privacy;
use Dev3\AiFormBuilder\Data\Tables;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Spam\Spam_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3fb_daily';

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
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3FB_DB_VERSION );
		Form_Repository::register();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3fb_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3fb_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3fb_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3fb_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Translations (on init, as WordPress 6.7+ expects).
	 */
	public static function textdomain() {
		load_plugin_textdomain( 'dev3-ai-form-builder', false, dirname( plugin_basename( DEV3FB_FILE ) ) . '/languages' );
	}

	/**
	 * Daily housekeeping.
	 */
	public static function daily() {
		Schema::prune_usage();
		Drafts::prune();
		Analytics::prune();
		Spam_Log::prune( 90 );
		Entries::prune( (int) Settings_Store::get( 'retention_days', 0 ) );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', array( __CLASS__, 'textdomain' ), 1 );
		self::register_schema();
		add_action( 'init', array( Form_Repository::class, 'register' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3FB_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		Privacy::init();
		add_action( 'init', array( Entry_Privacy::class, 'register' ) );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 2 );
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Entry_Insights::JOB, array( Entry_Insights::class, 'run_chunk' ) );
		add_action( 'init', static fn() => Assets::register( array( 'admin', 'frontend', 'block' ) ) );
		Frontend\Frontend::register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Forms_Controller() )->register_routes();
		( new Rest\Entries_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
