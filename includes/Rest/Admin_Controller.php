<?php
/**
 * Admin routes: dashboard, spam log, credentials, integration tests and builder metadata.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Rest;

use Dev3\AiFormBuilder\AI\Form_Generator;
use Dev3\AiFormBuilder\Core3\Api\Ai;
use Dev3\AiFormBuilder\Core3\Rest\Base_Controller;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Delivery\Integrations;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Payments\PayPal;
use Dev3\AiFormBuilder\Payments\Stripe;
use Dev3\AiFormBuilder\Secrets;
use Dev3\AiFormBuilder\Spam\Spam_Log;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$routes = array(
			'/dashboard'         => array( 'GET', 'dashboard' ),
			'/spam'              => array( 'GET', 'spam' ),
			'/spam/(?P<id>\d+)'  => array( 'PATCH, POST', 'spam_update' ),
			'/credentials'       => array( 'GET', 'credentials' ),
			'/credentials/save'  => array( 'POST', 'credentials_save' ),
			'/integrations/test' => array( 'POST', 'integration_test' ),
			'/meta'              => array( 'GET', 'meta' ),
		);
		foreach ( $routes as $route => $spec ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( $this, $spec[1] ),
					'permission_callback' => $manage,
				)
			);
		}
	}

	/**
	 * GET /dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		$forms   = Form_Repository::all();
		$per     = Entries::per_form();
		$funnel  = Analytics::per_form( 30 );
		$daily   = Entries::daily( 30 );
		$counts  = Entries::counts();
		$spam    = Spam_Log::counts( 30 );
		$views   = 0;
		$submits = 0;
		$top     = array();
		foreach ( $forms as $form ) {
			$f        = isset( $funnel[ $form['id'] ] ) ? $funnel[ $form['id'] ] : array(
				'views'   => 0,
				'submits' => 0,
			);
			$views   += $f['views'];
			$submits += $f['submits'];
			$top[]    = array(
				'id'         => $form['id'],
				'title'      => $form['title'],
				'status'     => $form['status'],
				'entries'    => isset( $per[ $form['id'] ] ) ? $per[ $form['id'] ]['total'] : 0,
				'unread'     => isset( $per[ $form['id'] ] ) ? $per[ $form['id'] ]['unread'] : 0,
				'views'      => $f['views'],
				'conversion' => $f['views'] ? round( min( 1, $f['submits'] / $f['views'] ), 3 ) : null,
			);
		}
		usort(
			$top,
			static function ( $a, $b ) {
				return $b['entries'] - $a['entries'];
			}
		);
		$recent = array();
		$cache  = array();
		foreach ( Entries::query(
			array(
				'per_page' => 6,
				'orderby'  => 'created_at',
			)
		)['rows'] as $entry ) {
			if ( ! array_key_exists( $entry['form_id'], $cache ) ) {
				$cache[ $entry['form_id'] ] = Form_Repository::get( $entry['form_id'] );
			}
			$form     = $cache[ $entry['form_id'] ];
			$recent[] = array(
				'id'         => $entry['id'],
				'form_title' => $form ? $form['title'] : '',
				'name'       => $form ? Entry_Format::sender_name( $form['definition'], $entry['data'] ) : '',
				'email'      => $entry['email'],
				'status'     => $entry['status'],
				'lead_score' => $entry['lead_score'],
				'sentiment'  => $entry['sentiment'],
				'intent'     => isset( $entry['ai']['intent'] ) ? $entry['ai']['intent'] : '',
				'created_at' => $entry['created_at'],
			);
		}
		$last30 = array_sum( wp_list_pluck( $daily, 'count' ) );
		return $this->respond(
			array(
				'forms'      => count( $forms ),
				'active'     => count( wp_list_filter( $forms, array( 'status' => 'active' ) ) ),
				'entries_30' => $last30,
				'unread'     => $counts['unread'],
				'total'      => $counts['all'],
				'views_30'   => $views,
				'conversion' => $views ? round( min( 1, $submits / $views ), 3 ) : null,
				'spam_30'    => $spam['block'] + $spam['spam'],
				'daily'      => $daily,
				'top'        => array_slice( $top, 0, 6 ),
				'recent'     => $recent,
				'ai'         => Ai::status(),
			)
		);
	}

	/**
	 * GET /spam.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function spam( \WP_REST_Request $request ) {
		$result           = Spam_Log::query(
			array(
				'page'     => (int) $request['page'],
				'per_page' => $request['per_page'] ? (int) $request['per_page'] : 20,
				'status'   => in_array( $request['status'], Spam_Log::STATUSES, true ) ? (string) $request['status'] : '',
				'action'   => in_array( $request['action'], array( 'block', 'spam', 'flag', 'allow' ), true ) ? (string) $request['action'] : '',
				'form_id'  => (int) $request['form'],
				'search'   => sanitize_text_field( (string) $request['search'] ),
			)
		);
		$result['counts'] = Spam_Log::counts( 30 );
		return $this->respond( $result );
	}

	/**
	 * PATCH /spam/{id} { status: not_spam|confirmed|open }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function spam_update( \WP_REST_Request $request ) {
		global $wpdb;
		$row    = Spam_Log::get( (int) $request['id'] );
		$params = (array) $request->get_json_params();
		$status = isset( $params['status'] ) ? (string) $params['status'] : '';
		if ( ! $row || ! in_array( $status, Spam_Log::STATUSES, true ) ) {
			return $this->respond( new \WP_Error( 'dev3fb_bad_request', __( 'Invalid request.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) ) );
		}
		Spam_Log::update( $row['id'], array( 'status' => $status ) );
		if ( 'not_spam' === $status ) {
			$table = Spam_Log::table();
			$hash  = $wpdb->get_var( $wpdb->prepare( "SELECT email_hash FROM {$table} WHERE id = %d", $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Spam_Log::allowlist( (string) $hash );
			if ( $row['entry_id'] ) {
				$entry = Entries::get( $row['entry_id'] );
				if ( $entry && 'spam' === $entry['status'] ) {
					Entries::update( $entry['id'], array( 'status' => 'unread' ) );
				}
			}
		}
		return $this->respond( Spam_Log::get( $row['id'] ) );
	}

	/**
	 * GET /credentials (set/not set only).
	 *
	 * @return \WP_REST_Response
	 */
	public function credentials() {
		return $this->respond(
			array(
				'secrets' => Secrets::status(),
				'stripe'  => array(
					'configured' => Stripe::configured(),
					'test_mode'  => Stripe::test_mode(),
					'webhook'    => rest_url( self::NS . '/payments/stripe' ),
				),
				'paypal'  => array( 'configured' => PayPal::configured() ),
			)
		);
	}

	/**
	 * POST /credentials/save { key: value }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function credentials_save( \WP_REST_Request $request ) {
		Secrets::update( (array) $request->get_json_params() );
		return $this->credentials();
	}

	/**
	 * POST /integrations/test { service }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function integration_test( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->respond( Integrations::test( isset( $params['service'] ) ? sanitize_key( (string) $params['service'] ) : '' ) );
	}

	/**
	 * GET /meta: pages (confirmation targets), languages, admin email.
	 *
	 * @return \WP_REST_Response
	 */
	public function meta() {
		$pages = array();
		foreach ( get_pages(
			array(
				'number'      => 200,
				'post_status' => 'publish',
			)
		) as $page ) {
			$pages[] = array(
				'value' => (string) $page->ID,
				'label' => get_the_title( $page ),
			);
		}
		return $this->respond(
			array(
				'pages'       => $pages,
				'languages'   => Form_Generator::languages(),
				'admin_email' => get_option( 'admin_email' ),
				'site_lang'   => substr( get_locale(), 0, 2 ),
			)
		);
	}
}
