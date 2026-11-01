<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Admin;

use Dev3\AiFormBuilder\Core3\Core\Assets;
use Dev3\AiFormBuilder\Core3\Core\Capabilities;
use Dev3\AiFormBuilder\Data\Entries;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffixes.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3FB_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu + hash-route submenus.
	 */
	public function menu() {
		$cap           = Capabilities::manage();
		$unread        = current_user_can( $cap ) ? Entries::counts()['unread'] : 0;
		$badge         = $unread ? ' <span class="awaiting-mod count-' . (int) $unread . '"><span class="pending-count">' . (int) number_format_i18n( $unread ) . '</span></span>' : '';
		$icon          = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path d="M4 2h9l3 3v13H4zM6 6h6v1.5H6zm0 3h8v1.5H6zm0 3h8v1.5H6zm0 3h5v1.5H6z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->hooks[] = (string) add_menu_page( DEV3FB_NAME, __( 'DEV3 AI Forms', 'dev3-ai-form-builder' ) . $badge, $cap, DEV3FB_SLUG, array( $this, 'render' ), $icon, 30 );
		$subs          = array(
			''          => __( 'Dashboard', 'dev3-ai-form-builder' ),
			'forms'     => __( 'All forms', 'dev3-ai-form-builder' ),
			'forms/new' => __( 'Add new', 'dev3-ai-form-builder' ),
			'entries'   => __( 'Entries', 'dev3-ai-form-builder' ),
			'spam'      => __( 'Spam shield', 'dev3-ai-form-builder' ),
			'settings'  => __( 'Settings', 'dev3-ai-form-builder' ),
		);
		foreach ( $subs as $route => $label ) {
			$slug = '' === $route ? DEV3FB_SLUG : DEV3FB_SLUG . '#/' . $route;
			$hook = add_submenu_page( DEV3FB_SLUG, $label . ' · ' . DEV3FB_NAME, $label, $cap, $slug, '' === $route ? array( $this, 'render' ) : '__return_null' );
			if ( $hook ) {
				$this->hooks[] = (string) $hook;
			}
		}
	}

	/**
	 * Mount point.
	 */
	public function render() {
		echo '<div class="wrap dev3fb-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3FB_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3fb-admin-root" class="dev3fb-root dev3fb-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( in_array( $hook, $this->hooks, true ) ) {
			Assets::enqueue( 'admin' );
			wp_enqueue_media();
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3FB_SLUG . '#/forms' ) ) . '">' . esc_html__( 'Forms', 'dev3-ai-form-builder' ) . '</a>' );
		return $links;
	}
}
