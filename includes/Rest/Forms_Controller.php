<?php
/**
 * Admin routes for forms, templates, insights and the AI form tools.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Rest;

use Dev3\AiFormBuilder\AI\Conversion_Advisor;
use Dev3\AiFormBuilder\AI\Form_Generator;
use Dev3\AiFormBuilder\AI\Translator;
use Dev3\AiFormBuilder\Core3\Rest\Base_Controller;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Forms\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Forms controller.
 */
class Forms_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = '/forms/(?P<id>\d+)';
		register_rest_route(
			self::NS,
			'/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/forms/options',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'options' ),
				'permission_callback' => array( $this, 'can_generate' ),
			)
		);
		register_rest_route(
			self::NS,
			$id,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT, PATCH, POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
				),
			)
		);
		$routes = array(
			$id . '/duplicate' => array( 'POST', 'duplicate' ),
			$id . '/insights'  => array( 'GET', 'insights' ),
			$id . '/advice'    => array( 'POST', 'advice' ),
			$id . '/apply-fix' => array( 'POST', 'apply_fix' ),
			$id . '/translate' => array( 'POST', 'translate' ),
			'/templates'       => array( 'GET', 'templates' ),
			'/ai/form'         => array( 'POST', 'generate' ),
			'/normalize'       => array( 'POST', 'normalize' ),
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
	 * Shapes a form for the admin.
	 *
	 * @param array $form  Form.
	 * @param bool  $full  Include definition.
	 * @param array $stats Optional stats.
	 * @return array
	 */
	private static function shape( array $form, $full = true, array $stats = array() ) {
		$out = array(
			'id'         => $form['id'],
			'title'      => $form['title'],
			'status'     => $form['status'],
			'created_at' => $form['created_at'],
			'updated_at' => $form['updated_at'],
			'shortcode'  => sprintf( '[dev3fb_form id="%d"]', $form['id'] ),
			'preview'    => \Dev3\AiFormBuilder\Frontend\Frontend::preview_url( $form['id'] ),
			'fields'     => count( Definition::input_fields( $form['definition'] ) ),
			'steps'      => count( $form['definition']['steps'] ),
			'payments'   => (bool) $form['definition']['payments']['enabled'],
			'languages'  => array_keys( $form['definition']['translations'] ),
			'ai_spam'    => (bool) $form['definition']['protection']['ai_spam'],
		);
		if ( $full ) {
			$out['definition'] = $form['definition'];
			$out['drafts']     = Drafts::count( $form['id'] );
			$out['counts']     = Entries::counts( $form['id'] );
		}
		return array_merge( $out, $stats );
	}

	/**
	 * GET /forms.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ) {
		$forms   = Form_Repository::all(
			array(
				'search' => (string) $request['search'],
				'status' => (string) $request['status'],
			)
		);
		$entries = Entries::per_form();
		$funnel  = Analytics::per_form( 30 );
		$out     = array();
		foreach ( $forms as $form ) {
			$views   = isset( $funnel[ $form['id'] ] ) ? $funnel[ $form['id'] ]['views'] : 0;
			$submits = isset( $funnel[ $form['id'] ] ) ? $funnel[ $form['id'] ]['submits'] : 0;
			$out[]   = self::shape(
				$form,
				false,
				array(
					'entries'    => isset( $entries[ $form['id'] ] ) ? $entries[ $form['id'] ]['total'] : 0,
					'unread'     => isset( $entries[ $form['id'] ] ) ? $entries[ $form['id'] ]['unread'] : 0,
					'views'      => $views,
					'conversion' => $views ? round( min( 1, $submits / $views ), 3 ) : null,
				)
			);
		}
		return $this->respond( $out );
	}

	/**
	 * POST /forms: { title, status, definition } or { template } or { blank }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$title  = isset( $params['title'] ) ? (string) $params['title'] : '';
		if ( ! empty( $params['template'] ) ) {
			$template = Templates::get( (string) $params['template'] );
			if ( ! $template ) {
				return $this->respond( new \WP_Error( 'dev3fb_unknown_template', __( 'Unknown template.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
			}
			$definition = $template['definition'];
			$title      = '' !== $title ? $title : $template['title'];
		} else {
			$definition = isset( $params['definition'] ) ? $params['definition'] : Definition::blank();
		}
		$id = Form_Repository::save(
			array(
				'title'      => '' !== $title ? $title : __( 'Untitled form', 'dev3-ai-form-builder' ),
				'status'     => isset( $params['status'] ) ? (string) $params['status'] : 'active',
				'definition' => $definition,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond( self::shape( Form_Repository::get( $id ) ), 201 );
	}

	/**
	 * Loads a form or 404s.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private static function load( \WP_REST_Request $request ) {
		$form = Form_Repository::get( (int) $request['id'] );
		return $form ? $form : new \WP_Error( 'dev3fb_not_found', __( 'Form not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /forms/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ) {
		$form = self::load( $request );
		return $this->respond( is_wp_error( $form ) ? $form : self::shape( $form ) );
	}

	/**
	 * PUT /forms/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		$params = (array) $request->get_json_params();
		$data   = array( 'id' => $form['id'] );
		foreach ( array( 'title', 'status', 'definition' ) as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$data[ $key ] = $params[ $key ];
			}
		}
		if ( ! isset( $data['definition'] ) ) {
			$data['definition'] = $form['definition'];
		}
		$id = Form_Repository::save( $data );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond( self::shape( Form_Repository::get( $id ) ) );
	}

	/**
	 * DELETE /forms/{id}?entries=1.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		return $this->respond( array( 'deleted' => Form_Repository::delete( $form['id'], ! empty( $request['entries'] ) ) ) );
	}

	/**
	 * POST /forms/{id}/duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate( \WP_REST_Request $request ) {
		$id = Form_Repository::duplicate( (int) $request['id'] );
		return $this->respond( is_wp_error( $id ) ? $id : self::shape( Form_Repository::get( $id ), false ), 201 );
	}

	/**
	 * GET /forms/options: id/title list for the block and page builders (editors).
	 *
	 * @return \WP_REST_Response
	 */
	public function options() {
		$out = array();
		foreach ( Form_Repository::all() as $form ) {
			$out[] = array(
				'value'     => $form['id'],
				'label'     => $form['title'],
				'status'    => $form['status'],
				'languages' => array_keys( $form['definition']['translations'] ),
			);
		}
		return $this->respond( $out );
	}

	/**
	 * GET /templates.
	 *
	 * @return \WP_REST_Response
	 */
	public function templates() {
		return $this->respond( Templates::summaries() );
	}

	/**
	 * POST /normalize: validates a definition (e.g. an imported JSON file) without saving.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function normalize( \WP_REST_Request $request ) {
		$params     = (array) $request->get_json_params();
		$definition = Definition::normalize( isset( $params['definition'] ) ? $params['definition'] : array(), $fixes );
		return $this->respond(
			array(
				'definition' => $definition,
				'fixes'      => $fixes,
			)
		);
	}

	/**
	 * GET /forms/{id}/insights?days=30: funnel + rule-based hints.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function insights( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		$days = $request['days'] ? max( 1, min( 365, (int) $request['days'] ) ) : 30;
		return $this->respond( Conversion_Advisor::overview( $form['id'], $form['definition'], $days ) );
	}

	/**
	 * POST /forms/{id}/advice: DEV3 AI conversion advice.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function advice( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		$days     = $request['days'] ? max( 1, min( 365, (int) $request['days'] ) ) : 30;
		$overview = Conversion_Advisor::overview( $form['id'], $form['definition'], $days );
		$advice   = Conversion_Advisor::advise( $form['definition'], $form['title'], $overview['funnel'] );
		return $this->respond( $advice );
	}

	/**
	 * POST /forms/{id}/apply-fix { item }: returns the changed definition (not saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply_fix( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		$params = (array) $request->get_json_params();
		$result = Conversion_Advisor::apply_fix( $form['definition'], isset( $params['item'] ) && is_array( $params['item'] ) ? $params['item'] : array() );
		return $this->respond( is_wp_error( $result ) ? $result : array( 'definition' => $result ) );
	}

	/**
	 * POST /forms/{id}/translate { lang }: draft translation (not saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function translate( \WP_REST_Request $request ) {
		$form = self::load( $request );
		if ( is_wp_error( $form ) ) {
			return $this->respond( $form );
		}
		return $this->respond( Translator::translate( $form['definition'], (string) $request['lang'] ) );
	}

	/**
	 * POST /ai/form { prompt, steps, language }: "Describe your form" draft (not saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function generate( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->respond(
			Form_Generator::generate(
				isset( $params['prompt'] ) ? sanitize_textarea_field( (string) $params['prompt'] ) : '',
				array(
					'steps'    => isset( $params['steps'] ) ? (string) $params['steps'] : 'auto',
					'language' => isset( $params['language'] ) ? (string) $params['language'] : '',
				)
			)
		);
	}
}
