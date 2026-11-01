<?php
/**
 * Visitor routes: submit, save (continue later), token refresh, funnel tracking, paste-to-fill
 * and the Stripe webhook.
 *
 * Security: core public_permission() (same-origin + per-IP rate limit; nonces are useless for
 * cached pages), the signed per-form token, honeypot/time trap/rate limit in the spam gate, and
 * every value is re-validated on the server from the stored form definition.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Rest;

use Dev3\AiFormBuilder\AI\Extractor;
use Dev3\AiFormBuilder\Core3\Core\Rate_Limiter;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Core3\Rest\Base_Controller;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Payments\Stripe;
use Dev3\AiFormBuilder\Submission\Form_Token;
use Dev3\AiFormBuilder\Submission\Submission_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Public controller.
 */
class Public_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$form_arg = array(
			'form' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => function ( $request ) {
					return $this->public_permission( $request, 'submit', 30 );
				},
				'args'                => $form_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => function ( $request ) {
					return $this->public_permission( $request, 'save', 10 );
				},
				'args'                => $form_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => function ( $request ) {
					return $this->public_permission( $request, 'token', 10 );
				},
				'args'                => $form_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'track' ),
				'permission_callback' => function ( $request ) {
					return $this->public_permission( $request, 'track', 120 );
				},
				'args'                => $form_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/parse',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'parse' ),
				'permission_callback' => function ( $request ) {
					return $this->public_permission( $request, 'parse', 10 );
				},
				'args'                => array_merge(
					$form_arg,
					array(
						'token' => array(
							'type'     => 'string',
							'required' => true,
						),
						'text'  => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				),
			)
		);
		register_rest_route(
			self::NS,
			'/payments/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stripe_webhook' ),
				// Stripe calls this server-to-server; the payload is authenticated by its signature.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Request params for the handler (body + query, files separately).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function params( \WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( ! $params ) {
			$params = (array) $request->get_json_params();
		}
		return is_array( $params ) ? $params : array();
	}

	/**
	 * POST /submit (multipart/form-data, like the HTML form).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function submit( \WP_REST_Request $request ) {
		$result = Submission_Handler::handle( (int) $request['form'], self::params( $request ), (array) $request->get_file_params() );
		$body   = array(
			'ok'           => $result['ok'],
			'code'         => $result['code'],
			'message'      => $result['message'],
			'errors'       => (object) $result['errors'],
			'step'         => $result['step'],
			'confirmation' => $result['confirmation'],
			'redirect'     => $result['redirect'],
		);
		return new \WP_REST_Response( $body, $result['status'] );
	}

	/**
	 * POST /save: stores a draft and returns a resume link (optionally emailed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $request ) {
		$form = Form_Repository::get( (int) $request['form'] );
		if ( ! $form || ! $form['definition']['settings']['save_continue'] ) {
			return $this->respond( new \WP_Error( 'dev3fb_disabled', __( 'Saving is not available for this form.', 'dev3-ai-form-builder' ), array( 'status' => 403 ) ) );
		}
		$params = self::params( $request );
		$check  = Form_Token::verify( isset( $params['dev3fb_token'] ) ? (string) $params['dev3fb_token'] : '', $form['id'] );
		if ( is_wp_error( $check ) ) {
			return $this->respond( $check );
		}
		$values = Submission_Handler::collect( $form['definition'], isset( $params['dev3fb'] ) && is_array( $params['dev3fb'] ) ? $params['dev3fb'] : array() );
		foreach ( Definition::fields( $form['definition'] ) as $field ) {
			if ( 'password' === $field['type'] ) {
				unset( $values[ $field['id'] ] ); // Never park passwords in drafts.
			}
		}
		$email = isset( $params['dev3fb_save_email'] ) ? sanitize_email( (string) $params['dev3fb_save_email'] ) : '';
		$token = Drafts::save( $form['id'], $values, isset( $params['dev3fb_step'] ) ? (int) $params['dev3fb_step'] : 0, is_email( $email ) ? $email : '', isset( $params['dev3fb_resume'] ) ? (string) $params['dev3fb_resume'] : '' );
		$page  = isset( $params['dev3fb_page'] ) && wp_validate_redirect( (string) $params['dev3fb_page'], '' ) ? (string) $params['dev3fb_page'] : home_url( '/' );
		$link  = add_query_arg( 'dev3fb_resume', $token, $page );
		$sent  = false;
		if ( is_email( $email ) && Rate_Limiter::hit( 'save_mail', Rate_Limiter::client_ip(), 3, HOUR_IN_SECONDS ) ) {
			/* translators: %s: form title */
			$subject = sprintf( __( 'Continue your form: %s', 'dev3-ai-form-builder' ), $form['title'] );
			/* translators: 1: link, 2: days */
			$body = sprintf( __( "Use this link to continue where you left off:\n\n%1\$s\n\nThe link works for %2\$d days.", 'dev3-ai-form-builder' ), $link, Drafts::ttl_days() );
			$sent = wp_mail( $email, $subject, $body );
		}
		return $this->respond(
			array(
				'token' => $token,
				'link'  => $link,
				'sent'  => (bool) $sent,
				'days'  => Drafts::ttl_days(),
			)
		);
	}

	/**
	 * POST /token: fresh token for long-open or cached pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function token( \WP_REST_Request $request ) {
		$form = Form_Repository::get( (int) $request['form'] );
		if ( ! $form || 'active' !== $form['status'] ) {
			return $this->respond( new \WP_Error( 'dev3fb_unknown_form', __( 'Unknown form.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
		}
		// Issue the token "back-dated" by the time trap, so a refresh doesn't trip it.
		return $this->respond( array( 'token' => Form_Token::create( $form['id'], time() - (int) $form['definition']['protection']['min_seconds'] ) ) );
	}

	/**
	 * POST /track: { form, session, events: [ { event, step, field } ] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ) {
		if ( ! Settings_Store::get( 'analytics', true ) ) {
			return $this->respond( array( 'ok' => true ) );
		}
		$form = Form_Repository::get( (int) $request['form'] );
		if ( ! $form ) {
			return $this->respond( array( 'ok' => false ) );
		}
		$params  = self::params( $request );
		$session = isset( $params['session'] ) ? (string) $params['session'] : '';
		$events  = isset( $params['events'] ) && is_array( $params['events'] ) ? array_slice( $params['events'], 0, 20 ) : array();
		if ( is_string( $params['events'] ?? null ) ) {
			$decoded = json_decode( $params['events'], true );
			$events  = is_array( $decoded ) ? array_slice( $decoded, 0, 20 ) : array();
		}
		$known = wp_list_pluck( Definition::fields( $form['definition'] ), 'id' );
		$count = 0;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || empty( $event['event'] ) || 'submit' === $event['event'] ) {
				continue;
			}
			$field = isset( $event['field'] ) ? (string) $event['field'] : '';
			if ( '' !== $field && ! in_array( $field, $known, true ) ) {
				continue;
			}
			$count += Analytics::record( $form['id'], $session, (string) $event['event'], isset( $event['step'] ) ? (int) $event['step'] : 0, $field ) ? 1 : 0;
		}
		return $this->respond( array( 'recorded' => $count ) );
	}

	/**
	 * POST /parse: paste-to-fill.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $request ) {
		$form = Form_Repository::get( (int) $request['form'] );
		if ( ! $form ) {
			return $this->respond( new \WP_Error( 'dev3fb_unknown_form', __( 'Unknown form.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
		}
		$check = Form_Token::verify( (string) $request['token'], $form['id'] );
		if ( is_wp_error( $check ) ) {
			return $this->respond( $check );
		}
		if ( empty( $form['definition']['protection']['paste_fill'] ) || '' === Settings_Store::api_key() ) {
			return $this->respond( new \WP_Error( 'dev3fb_disabled', __( 'This feature is not enabled for this form.', 'dev3-ai-form-builder' ), array( 'status' => 403 ) ) );
		}
		$cap = (int) Settings_Store::get( 'daily_ai_cap', 2000 );
		if ( $cap > 0 && ! Rate_Limiter::hit( 'public_day', 'site', $cap, DAY_IN_SECONDS ) ) {
			return $this->respond( new \WP_Error( 'dev3fb_daily_cap', __( 'Autofill is unavailable right now.', 'dev3-ai-form-builder' ), array( 'status' => 429 ) ) );
		}
		$text = mb_substr( trim( sanitize_textarea_field( (string) $request['text'] ) ), 0, 6000 );
		if ( mb_strlen( $text ) < 10 ) {
			return $this->respond( new \WP_Error( 'dev3fb_too_short', __( 'The text is too short.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) ) );
		}
		$lang = isset( $request['lang'] ) ? Definition::lang_code( (string) $request['lang'] ) : '';
		$def  = '' !== $lang ? Definition::translate( $form['definition'], $lang ) : $form['definition'];
		$out  = Extractor::extract( $text, Extractor::targets( $def, $def['protection']['paste_source'] ), $form['title'] );
		if ( is_wp_error( $out ) ) {
			return $this->respond( new \WP_Error( 'dev3fb_ai_unavailable', __( 'Autofill is unavailable right now.', 'dev3-ai-form-builder' ), array( 'status' => 503 ) ) );
		}
		return $this->respond(
			array(
				'fields' => $out,
				'count'  => count( $out ),
			)
		);
	}

	/**
	 * POST /payments/stripe (webhook).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stripe_webhook( \WP_REST_Request $request ) {
		$result = Stripe::webhook( (string) $request->get_body(), (string) $request->get_header( 'stripe_signature' ) );
		return $this->respond( is_wp_error( $result ) ? $result : array( 'received' => true ) );
	}
}
