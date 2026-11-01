<?php
/**
 * Plugin Name:       DEV3 AI Form Builder for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-form-builder
 * Description:       Drag-and-drop form builder with multi-step, logic, calculations, payments, entries and DEV3 AI form generation, entry intelligence and spam shield.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-form-builder
 * Domain Path:       /languages
 *
 * @package Dev3\AiFormBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3FB_VERSION', '1.0.0' );
define( 'DEV3FB_FILE', __FILE__ );
define( 'DEV3FB_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3FB_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3FB_SLUG', 'dev3-ai-form-builder' );
define( 'DEV3FB_NAME', 'DEV3 AI Form Builder for WordPress' );
define( 'DEV3FB_MIN_PHP', '7.4' );
define( 'DEV3FB_MIN_WP', '6.0' );
define( 'DEV3FB_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3FB_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiFormBuilder\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3FB_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiFormBuilder\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiFormBuilder\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiFormBuilder\Plugin', 'instance' ) );
