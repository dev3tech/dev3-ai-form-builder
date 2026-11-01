<?php
/**
 * Base test case: Gemini and third-party HTTP are faked through pre_http_request, emails are
 * captured through pre_wp_mail, and forms are created from compact field lists.
 *
 * @package Dev3\AiFormBuilder
 */

use Dev3\AiFormBuilder\Core3\Api\Ai;
use Dev3\AiFormBuilder\Core3\Core\Cache;
use Dev3\AiFormBuilder\Core3\Core\Schema;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Core3\Data\Usage_Repository;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Form_Token;

// phpcs:ignoreFile -- test helpers.

abstract class Dev3fb_TestCase extends WP_UnitTestCase {

	/** @var array Captured HTTP requests: [ url, method, headers, body, json ]. */
	protected $http = array();

	/** @var array Route => callable|array response (matched by substring, first match wins). */
	protected $routes = array();

	/** @var array Gemini JSON payloads queue (each becomes one generateContent answer). */
	protected $gemini = array();

	/** @var array Captured emails. */
	protected $mails = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3FB_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client  = null;
		$this->http   = array();
		$this->routes = array();
		$this->gemini = array();
		$this->mails  = array();
		add_filter( 'dev3fb_retry_delay_ms', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		delete_option( 'dev3fb_secrets' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_filter( 'dev3fb_retry_delay_ms', '__return_zero' );
		parent::tear_down();
	}

	protected function set_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	protected function respond( $code, $body, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	protected function gemini_text( $text ) {
		return array(
			'candidates'    => array(
				array(
					'content'      => array( 'parts' => array( array( 'text' => $text ) ) ),
					'finishReason' => 'STOP',
				),
			),
			'usageMetadata' => array(
				'promptTokenCount'     => 100,
				'candidatesTokenCount' => 50,
				'totalTokenCount'      => 150,
			),
		);
	}

	public function fake_http( $pre, $args, $url ) {
		$body = isset( $args['body'] ) ? $args['body'] : '';
		$this->http[] = array(
			'url'     => $url,
			'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => $body,
			'json'    => is_string( $body ) ? json_decode( $body, true ) : null,
		);
		if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			$next = $this->gemini ? array_shift( $this->gemini ) : array();
			if ( $next instanceof WP_Error ) {
				return $next;
			}
			if ( is_array( $next ) && isset( $next['__status'] ) ) {
				return $this->respond( $next['__status'], array( 'error' => array( 'message' => 'Down', 'status' => 'UNAVAILABLE' ) ) );
			}
			return $this->respond( 200, $this->gemini_text( is_string( $next ) ? $next : wp_json_encode( $next ) ) );
		}
		foreach ( $this->routes as $match => $response ) {
			if ( false !== strpos( $url, $match ) ) {
				$response = is_callable( $response ) ? $response( end( $this->http ) ) : $response;
				return $this->respond( $response[0], $response[1] );
			}
		}
		return $this->respond( 404, array( 'message' => 'no fake route for ' . $url ) );
	}

	/**
	 * Requests whose URL contains a string.
	 */
	protected function requests_to( $needle ) {
		return array_values(
			array_filter(
				$this->http,
				static function ( $r ) use ( $needle ) {
					return false !== strpos( $r['url'], $needle );
				}
			)
		);
	}

	/**
	 * Creates a form from fields (one step) or a full definition.
	 */
	protected function make_form( array $fields_or_definition, array $extra = array(), $title = 'Test form' ) {
		$definition = isset( $fields_or_definition['steps'] ) ? $fields_or_definition : array( 'steps' => array( array( 'fields' => $fields_or_definition ) ) );
		$definition = array_merge( $definition, $extra );
		if ( ! isset( $definition['protection'] ) ) {
			$definition['protection'] = array( 'min_seconds' => 0 );
		}
		$id = Form_Repository::save(
			array(
				'title'      => $title,
				'status'     => 'active',
				'definition' => $definition,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Submission params with a valid, old-enough token.
	 */
	protected function params( $form_id, array $values, array $extra = array() ) {
		return array_merge(
			array(
				'dev3fb'       => $values,
				'dev3fb_token' => Form_Token::create( $form_id, time() - 30 ),
				'dev3fb_page'  => home_url( '/contact/' ),
			),
			$extra
		);
	}

	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function rest( $method, $route, array $params = array(), $nonce = true, array $headers = array() ) {
		$req = new WP_REST_Request( $method, '/dev3fb/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		} else {
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
		}
		return rest_do_request( $req );
	}
}
