<?php
/**
 * Front end: [dev3fb_form] shortcode, the "Form" block, assets, the no-JS post/redirect/get
 * round trip, "continue later" links and payment returns.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Frontend;

use Dev3\AiFormBuilder\Core3\Core\Assets;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Payments\Payments;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class Frontend {

	/**
	 * Payment return result for this request.
	 *
	 * @var array|null
	 */
	private static $payment = null;

	/**
	 * Hooks.
	 */
	public static function register() {
		add_shortcode( 'dev3fb_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_preview' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_post' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_payment_return' ), 6 );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'redirect_hosts' ) );
	}

	/**
	 * Payment pages we may redirect to.
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	public static function redirect_hosts( $hosts ) {
		return array_merge( (array) $hosts, array( 'checkout.stripe.com', 'www.paypal.com', 'www.sandbox.paypal.com' ) );
	}

	/**
	 * Registers the block (server-rendered).
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'dev3fb/form',
			array(
				'api_version'     => 2,
				'editor_script'   => Assets::handle( 'block' ),
				'editor_style'    => Assets::handle( 'frontend' ),
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'formId'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'lang'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'align'   => array( 'wide', 'full' ),
					'html'    => false,
					'spacing' => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Block render.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$id    = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		$html  = self::shortcode(
			array(
				'id'   => $id,
				'lang' => isset( $attributes['lang'] ) ? (string) $attributes['lang'] : '',
			)
		);
		$class = isset( $attributes['className'] ) ? sanitize_html_class( (string) $attributes['className'] ) : '';
		return '<div class="wp-block-dev3fb-form' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">' . $html . '</div>';
	}

	/**
	 * Visitor language: shortcode attribute, else WPML/Polylang/site locale when a translation exists.
	 *
	 * @param array  $definition Definition.
	 * @param string $requested  Requested code.
	 * @return string
	 */
	public static function language( array $definition, $requested = '' ) {
		$requested = Definition::lang_code( $requested );
		if ( '' !== $requested ) {
			return $requested;
		}
		if ( empty( $definition['translations'] ) ) {
			return '';
		}
		$candidates = array();
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$candidates[] = (string) constant( 'ICL_LANGUAGE_CODE' );
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$candidates[] = (string) call_user_func( 'pll_current_language' );
		}
		$candidates[] = determine_locale();
		foreach ( $candidates as $code ) {
			$code = Definition::lang_code( $code );
			if ( '' === $code ) {
				continue;
			}
			if ( isset( $definition['translations'][ $code ] ) ) {
				return $code;
			}
			if ( isset( $definition['translations'][ substr( $code, 0, 2 ) ] ) ) {
				return substr( $code, 0, 2 );
			}
		}
		return '';
	}

	/**
	 * Enqueues the front-end bundle.
	 */
	public static function enqueue() {
		Assets::enqueue( 'frontend' );
	}

	/**
	 * [dev3fb_form id="12" lang="de"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'lang' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'dev3fb_form'
		);
		$form = Form_Repository::get( (int) $atts['id'] );
		if ( ! $form ) {
			return current_user_can( 'edit_posts' ) ? '<p class="dev3fb-notice">' . esc_html__( 'Form not found. Pick a form in the block settings.', 'dev3-ai-form-builder' ) . '</p>' : '';
		}
		if ( 'active' !== $form['status'] && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$lang = self::language( $form['definition'], (string) $atts['lang'] );
		if ( '' !== $lang ) {
			$form['definition'] = Definition::translate( $form['definition'], $lang );
		}
		self::enqueue();
		return Renderer::render( $form, self::state_for( $form ) );
	}

	/**
	 * Render options from the URL (no-JS results, resume links, payment returns).
	 *
	 * @param array $form Form.
	 * @return array
	 */
	private static function state_for( array $form ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only state keyed by random tokens.
		$options = array( 'preview' => current_user_can( 'edit_posts' ) );
		if ( isset( $_GET['dev3fb_done'] ) ) {
			$state = get_transient( 'dev3fb_done_' . sanitize_key( wp_unslash( $_GET['dev3fb_done'] ) ) );
			if ( is_array( $state ) && (int) $state['form_id'] === (int) $form['id'] ) {
				$options['confirmation'] = $state['message'];
			}
		}
		if ( isset( $_GET['dev3fb_err'] ) ) {
			$state = get_transient( 'dev3fb_err_' . sanitize_key( wp_unslash( $_GET['dev3fb_err'] ) ) );
			if ( is_array( $state ) && (int) $state['form_id'] === (int) $form['id'] ) {
				$options = array_merge( $options, array_intersect_key( $state, array_flip( array( 'values', 'errors', 'message', 'step' ) ) ) );
			}
		}
		if ( isset( $_GET['dev3fb_resume'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['dev3fb_resume'] ) );
			$draft = Drafts::get( $token, $form['id'] );
			if ( $draft ) {
				$options['values'] = $draft['data'];
				$options['step']   = $draft['step'];
				$options['resume'] = $token;
			} else {
				$options['message'] = __( 'This saved form has expired. Please start again.', 'dev3-ai-form-builder' );
			}
		}
		// phpcs:enable
		if ( self::$payment && (int) self::$payment['form_id'] === (int) $form['id'] ) {
			$entry = Entries::get( self::$payment['entry_id'] );
			switch ( self::$payment['status'] ) {
				case 'paid':
					$confirmation = Submission_Handler::confirmation( $form, $entry );
					if ( 'redirect' !== $confirmation['type'] ) {
						$options['confirmation'] = $confirmation['message'];
					}
					break;
				case 'cancelled':
					$options['values']  = $entry ? $entry['data'] : array();
					$options['message'] = __( 'The payment was cancelled. You can try again.', 'dev3-ai-form-builder' );
					break;
				case 'pending':
					$options['confirmation'] = '<p>' . esc_html__( 'Thanks! We are waiting for the payment confirmation and will email you shortly.', 'dev3-ai-form-builder' ) . '</p>';
					break;
				default:
					$options['message'] = __( 'We could not confirm the payment. If you were charged, please contact us.', 'dev3-ai-form-builder' );
			}
		}
		return $options;
	}

	/**
	 * No-JS submission (post/redirect/get).
	 */
	public static function maybe_handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; protected by the signed form token, honeypot and rate limits.
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['dev3fb_form_id'] ) || wp_doing_ajax() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}
		$form_id = absint( $_POST['dev3fb_form_id'] );
		$params  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in Submission_Handler.
		// phpcs:enable
		$params           = is_array( $params ) ? $params : array();
		$params['dev3fb'] = isset( $_POST['dev3fb'] ) && is_array( $_POST['dev3fb'] ) ? $_POST['dev3fb'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validator unslashes + sanitizes.
		$result           = Submission_Handler::handle( $form_id, $params, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back             = isset( $params['dev3fb_page'] ) && wp_validate_redirect( (string) $params['dev3fb_page'], '' ) ? (string) $params['dev3fb_page'] : Renderer::current_url();
		$key              = strtolower( wp_generate_password( 20, false, false ) );
		if ( $result['ok'] ) {
			if ( ! empty( $result['confirmation'] ) && 'redirect' === $result['confirmation']['type'] ) {
				// Admin-configured confirmation URL or the payment provider's checkout page.
				wp_redirect( esc_url_raw( $result['confirmation']['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
			set_transient(
				'dev3fb_done_' . $key,
				array(
					'form_id' => $form_id,
					'message' => $result['confirmation'] ? $result['confirmation']['message'] : '',
				),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( add_query_arg( 'dev3fb_done', $key, $back ) . '#dev3fb-' . $form_id . '-1' );
			exit;
		}
		set_transient(
			'dev3fb_err_' . $key,
			array(
				'form_id' => $form_id,
				'errors'  => $result['errors'],
				'message' => $result['message'],
				'step'    => $result['step'],
				'values'  => array(),
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'dev3fb_err', $key, $back ) );
		exit;
	}

	/**
	 * Browser back from Stripe/PayPal.
	 */
	public static function maybe_handle_payment_return() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified with an HMAC per entry.
		if ( empty( $_GET['dev3fb_pay'] ) || empty( $_GET['dev3fb_entry'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query         = array_map( 'sanitize_text_field', wp_unslash( array_intersect_key( $_GET, array_flip( array( 'dev3fb_pay', 'dev3fb_entry', 'dev3fb_sig', 'session_id', 'token' ) ) ) ) );
		self::$payment = Payments::handle_return( $query );
		if ( 'paid' === self::$payment['status'] ) {
			$entry = Entries::get( self::$payment['entry_id'] );
			$form  = $entry ? Form_Repository::get( $entry['form_id'] ) : null;
			if ( $form ) {
				$confirmation = Submission_Handler::confirmation( $form, $entry );
				if ( 'redirect' === $confirmation['type'] ) {
					wp_redirect( esc_url_raw( $confirmation['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-configured confirmation URL.
					exit;
				}
			}
		}
	}

	/**
	 * Preview URL for the builder (editors only).
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	public static function preview_url( $form_id ) {
		return add_query_arg(
			array(
				'dev3fb_preview' => (int) $form_id,
				'_wpnonce'       => wp_create_nonce( 'dev3fb_preview' ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Renders ?dev3fb_preview=ID in a minimal page that loads the theme's styles.
	 */
	public static function maybe_preview() {
		if ( empty( $_GET['dev3fb_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dev3fb_preview' ) ) {
			wp_die( esc_html__( 'You are not allowed to preview this form.', 'dev3-ai-form-builder' ), 403 );
		}
		$form_id = absint( $_GET['dev3fb_preview'] );
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$html    = self::shortcode(
			array(
				'id'   => $form_id,
				'lang' => $lang,
			)
		);
		$form    = Form_Repository::get( $form_id );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper output.
		echo '<title>' . esc_html( $form ? $form['title'] : '' ) . '</title>';
		wp_head();
		echo '<style>body.dev3fb-preview{margin:0;padding:clamp(16px,4vw,48px) 16px;background:#f6f7f9}.dev3fb-preview-card{max-width:760px;margin:0 auto;background:#fff;border-radius:12px;padding:clamp(20px,4vw,40px);box-shadow:0 1px 2px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06)}.dev3fb-preview-bar{max-width:760px;margin:0 auto 12px;font:13px/1.4 system-ui,sans-serif;color:#50575e}</style>';
		echo '</head><body class="dev3fb-preview">';
		echo '<div class="dev3fb-preview-bar">' . esc_html__( 'Preview: submissions are saved as real entries.', 'dev3-ai-form-builder' ) . '</div>';
		echo '<main class="dev3fb-preview-card">' . ( $form ? '<h1 style="margin-top:0">' . esc_html( $form['title'] ) . '</h1>' : '' ) . $html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by Renderer with escaping.
		wp_footer();
		echo '</body></html>';
		exit;
	}

	/**
	 * Test helper.
	 *
	 * @param array|null $payment Payment state.
	 */
	public static function set_payment_state( $payment ) {
		self::$payment = $payment;
	}
}
