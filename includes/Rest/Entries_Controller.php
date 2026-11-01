<?php
/**
 * Admin routes for entries: list, detail, status, bulk actions, CSV export, private file
 * downloads, entry intelligence, AI auto-replies and resending.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Rest;

use Dev3\AiFormBuilder\AI\Auto_Reply;
use Dev3\AiFormBuilder\AI\Entry_Insights;
use Dev3\AiFormBuilder\Core3\Core\Jobs;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Core3\Rest\Base_Controller;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Delivery\Dispatcher;
use Dev3\AiFormBuilder\Export\Csv;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Submission\Entry_Format;
use Dev3\AiFormBuilder\Submission\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Entries controller.
 */
class Entries_Controller extends Base_Controller {

	const BULK = array( 'read', 'unread', 'star', 'unstar', 'spam', 'not_spam', 'trash', 'restore', 'delete', 'analyze' );

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = '/entries/(?P<id>\d+)';
		register_rest_route(
			self::NS,
			'/entries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $manage,
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
					'methods'             => 'PATCH, PUT, POST',
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
			'/entries/bulk'      => array( 'POST', 'bulk' ),
			'/entries/export'    => array( 'GET', 'export' ),
			'/entries/analyze'   => array( 'POST', 'analyze_all' ),
			$id . '/file'        => array( 'GET', 'file' ),
			$id . '/analyze'     => array( 'POST', 'analyze' ),
			$id . '/reply-draft' => array( 'POST', 'reply_draft' ),
			$id . '/reply'       => array( 'POST', 'reply' ),
			$id . '/resend'      => array( 'POST', 'resend' ),
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
	 * Light row for tables.
	 *
	 * @param array $entry  Entry.
	 * @param array $titles Form titles by id.
	 * @param array $forms  Forms cache (by reference).
	 * @return array
	 */
	private static function row( array $entry, array $titles, array &$forms ) {
		if ( ! array_key_exists( $entry['form_id'], $forms ) ) {
			$forms[ $entry['form_id'] ] = Form_Repository::get( $entry['form_id'] );
		}
		$form    = $forms[ $entry['form_id'] ];
		$preview = array();
		if ( $form ) {
			foreach ( Entry_Format::rows( $form['definition'], $entry['data'] ) as $row ) {
				if ( in_array( $row['type'], array( 'password', 'signature', 'file', 'consent', 'hidden' ), true ) ) {
					continue;
				}
				$preview[] = array(
					'label' => $row['label'],
					'value' => mb_substr( $row['value'], 0, 120 ),
				);
				if ( count( $preview ) >= 3 ) {
					break;
				}
			}
		}
		return array(
			'id'             => $entry['id'],
			'form_id'        => $entry['form_id'],
			'form_title'     => isset( $titles[ $entry['form_id'] ] ) ? $titles[ $entry['form_id'] ] : __( '(deleted form)', 'dev3-ai-form-builder' ),
			'status'         => $entry['status'],
			'starred'        => $entry['starred'],
			'email'          => $entry['email'],
			'name'           => $form ? Entry_Format::sender_name( $form['definition'], $entry['data'] ) : '',
			'preview'        => $preview,
			'lead_score'     => $entry['lead_score'],
			'sentiment'      => $entry['sentiment'],
			'intent'         => isset( $entry['ai']['intent'] ) ? $entry['ai']['intent'] : '',
			'category'       => isset( $entry['ai']['category'] ) ? $entry['ai']['category'] : '',
			'payment_status' => $entry['payment_status'],
			'payment_total'  => $entry['payment_total'],
			'currency'       => $entry['payment_currency'],
			'replied'        => ! empty( $entry['meta']['replied_at'] ),
			'created_at'     => $entry['created_at'],
		);
	}

	/**
	 * Form titles by id.
	 *
	 * @return array
	 */
	private static function titles() {
		$out = array();
		foreach ( Form_Repository::all() as $form ) {
			$out[ $form['id'] ] = $form['title'];
		}
		return $out;
	}

	/**
	 * Query args from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function query_args( \WP_REST_Request $request ) {
		return array(
			'form_id'  => (int) $request['form'],
			'status'   => sanitize_key( (string) $request['status'] ),
			'search'   => sanitize_text_field( (string) $request['search'] ),
			'page'     => max( 1, (int) $request['page'] ),
			'per_page' => $request['per_page'] ? (int) $request['per_page'] : 20,
			'orderby'  => sanitize_key( (string) $request['orderby'] ),
			'order'    => sanitize_key( (string) $request['order'] ),
			'after'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request['after'] ) ? $request['after'] . ' 00:00:00' : '',
			'before'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request['before'] ) ? $request['before'] . ' 23:59:59' : '',
		);
	}

	/**
	 * GET /entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ) {
		$args   = self::query_args( $request );
		$result = Entries::query( $args );
		$titles = self::titles();
		$forms  = array();
		$rows   = array();
		foreach ( $result['rows'] as $entry ) {
			$rows[] = self::row( $entry, $titles, $forms );
		}
		return $this->respond(
			array(
				'rows'   => $rows,
				'total'  => $result['total'],
				'counts' => Entries::counts( $args['form_id'] ),
			)
		);
	}

	/**
	 * Loads an entry + form or errors.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error [ entry, form|null ]
	 */
	private static function load( \WP_REST_Request $request ) {
		$entry = Entries::get( (int) $request['id'] );
		if ( ! $entry ) {
			return new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) );
		}
		return array( $entry, Form_Repository::get( $entry['form_id'] ) );
	}

	/**
	 * Full entry for the drawer.
	 *
	 * @param array      $entry Entry.
	 * @param array|null $form  Form.
	 * @return array
	 */
	private static function detail( array $entry, $form ) {
		$fields = array();
		if ( $form ) {
			foreach ( Entry_Format::rows( $form['definition'], $entry['data'], true ) as $row ) {
				$fields[] = $row;
			}
		}
		$files = array();
		foreach ( Uploads::files_of( $entry ) as $file ) {
			$files[] = array(
				'field' => $file['field'],
				'index' => $file['index'],
				'name'  => $file['name'],
				'size'  => (int) $file['size'],
				'type'  => $file['type'],
				'image' => 0 === strpos( (string) $file['type'], 'image/' ),
				'url'   => add_query_arg(
					array(
						'field'    => rawurlencode( $file['field'] ),
						'index'    => (int) $file['index'],
						'_wpnonce' => wp_create_nonce( 'wp_rest' ),
					),
					rest_url( self::NS . '/entries/' . $entry['id'] . '/file' )
				),
			);
		}
		$meta = $entry['meta'];
		unset( $meta['user_agent'] );
		return array(
			'entry'      => array_merge( $entry, array( 'meta' => $meta ) ),
			'form_title' => $form ? $form['title'] : __( '(deleted form)', 'dev3-ai-form-builder' ),
			'fields'     => $fields,
			'files'      => $files,
			'log'        => Entry_Log::for_entry( $entry['id'] ),
			'name'       => $form ? Entry_Format::sender_name( $form['definition'], $entry['data'] ) : '',
			'ai_ready'   => '' !== Settings_Store::api_key() && Settings_Store::get( 'ai_enabled', true ),
		);
	}

	/**
	 * GET /entries/{id} (marks it read).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ) {
		$loaded = self::load( $request );
		if ( is_wp_error( $loaded ) ) {
			return $this->respond( $loaded );
		}
		list( $entry, $form ) = $loaded;
		if ( 'unread' === $entry['status'] ) {
			Entries::update( $entry['id'], array( 'status' => 'read' ) );
			$entry['status'] = 'read';
		}
		return $this->respond( self::detail( $entry, $form ) );
	}

	/**
	 * PATCH /entries/{id} { status, starred, note }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( \WP_REST_Request $request ) {
		$loaded = self::load( $request );
		if ( is_wp_error( $loaded ) ) {
			return $this->respond( $loaded );
		}
		list( $entry, $form ) = $loaded;
		$params               = (array) $request->get_json_params();
		$patch                = array();
		if ( isset( $params['status'] ) && in_array( $params['status'], Entries::STATUSES, true ) ) {
			$patch['status'] = $params['status'];
			Entry_Log::add( $entry['id'], 'status', 'info', sprintf( 'Status changed to %s.', $params['status'] ) );
		}
		if ( isset( $params['starred'] ) ) {
			$patch['starred'] = $params['starred'] ? 1 : 0;
		}
		if ( $patch ) {
			Entries::update( $entry['id'], $patch );
		}
		if ( isset( $params['note'] ) && '' !== trim( (string) $params['note'] ) ) {
			Entry_Log::add( $entry['id'], 'note', 'info', sanitize_textarea_field( (string) $params['note'] ) );
		}
		return $this->respond( self::detail( Entries::get( $entry['id'] ), $form ) );
	}

	/**
	 * DELETE /entries/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Entries::delete( (int) $request['id'] ) ) );
	}

	/**
	 * POST /entries/bulk { ids, action }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$action = isset( $params['action'] ) ? (string) $params['action'] : '';
		$ids    = array_slice( array_filter( array_map( 'absint', isset( $params['ids'] ) ? (array) $params['ids'] : array() ) ), 0, 500 );
		if ( ! in_array( $action, self::BULK, true ) || ! $ids ) {
			return $this->respond( new \WP_Error( 'dev3fb_bad_bulk', __( 'Choose entries and an action.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) ) );
		}
		if ( 'analyze' === $action ) {
			$job = Jobs::create( Entry_Insights::JOB, $ids, array(), 5 );
			return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ) );
		}
		$map  = array(
			'read'     => array( 'status' => 'read' ),
			'unread'   => array( 'status' => 'unread' ),
			'star'     => array( 'starred' => 1 ),
			'unstar'   => array( 'starred' => 0 ),
			'spam'     => array( 'status' => 'spam' ),
			'not_spam' => array( 'status' => 'read' ),
			'trash'    => array( 'status' => 'trash' ),
			'restore'  => array( 'status' => 'read' ),
		);
		$done = 0;
		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$done += Entries::delete( $id ) ? 1 : 0;
			} elseif ( Entries::get( $id ) ) {
				$done += Entries::update( $id, $map[ $action ] ) ? 1 : 0;
			}
		}
		return $this->respond( array( 'updated' => $done ) );
	}

	/**
	 * GET /entries/export?form=&status=: CSV text (the browser saves it as a file).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$args = self::query_args( $request );
		if ( ! $args['form_id'] ) {
			return $this->respond( new \WP_Error( 'dev3fb_export_form', __( 'Choose a form to export.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) ) );
		}
		$form = Form_Repository::get( $args['form_id'] );
		if ( ! $form ) {
			return $this->respond( new \WP_Error( 'dev3fb_not_found', __( 'Form not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
		}
		$args['per_page'] = 5000;
		$args['page']     = 1;
		$rows             = Entries::query( $args )['rows'];
		return $this->respond(
			array(
				'filename' => sanitize_file_name( $form['title'] . '-entries-' . gmdate( 'Y-m-d' ) . '.csv' ),
				'csv'      => Csv::build( $form, $rows ),
				'count'    => count( $rows ),
			)
		);
	}

	/**
	 * GET /entries/{id}/file?field=&index=&_wpnonce= (streams a private upload).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|void
	 */
	public function file( \WP_REST_Request $request ) {
		$entry = Entries::get( (int) $request['id'] );
		if ( ! $entry ) {
			return $this->respond( new \WP_Error( 'dev3fb_not_found', __( 'Entry not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
		}
		$field = sanitize_key( rawurldecode( (string) $request['field'] ) );
		$index = (int) $request['index'];
		foreach ( Uploads::files_of( $entry ) as $file ) {
			if ( $file['field'] === $field && (int) $file['index'] === $index ) {
				$path = Uploads::absolute( $file['path'] );
				if ( ! $path ) {
					break;
				}
				/**
				 * Short-circuits streaming (tests).
				 *
				 * @param bool   $stream Stream.
				 * @param string $path   Path.
				 */
				if ( ! apply_filters( 'dev3fb_stream_file', true, $path ) ) {
					return $this->respond(
						array(
							'path' => $path,
							'name' => $file['name'],
						)
					);
				}
				$inline = 0 === strpos( (string) $file['type'], 'image/' );
				nocache_headers();
				header( 'Content-Type: ' . ( $inline ? $file['type'] : 'application/octet-stream' ) );
				header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $file['name'] ) . '"' );
				header( 'Content-Length: ' . (string) filesize( $path ) );
				header( 'X-Content-Type-Options: nosniff' );
				readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				exit;
			}
		}
		return $this->respond( new \WP_Error( 'dev3fb_not_found', __( 'File not found.', 'dev3-ai-form-builder' ), array( 'status' => 404 ) ) );
	}

	/**
	 * POST /entries/{id}/analyze { rules?: bool }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = Entry_Insights::analyze( (int) $request['id'], empty( $params['rules'] ) );
		return $this->respond( is_wp_error( $result ) ? $result : array( 'ai' => $result ) );
	}

	/**
	 * POST /entries/analyze { form, only_missing }: background job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze_all( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$rows   = Entries::query(
			array(
				'form_id'  => isset( $params['form'] ) ? (int) $params['form'] : 0,
				'per_page' => 1000,
			)
		)['rows'];
		$ids    = array();
		foreach ( $rows as $row ) {
			if ( empty( $params['only_missing'] ) || empty( $row['ai'] ) || 'rules' === ( isset( $row['ai']['source'] ) ? $row['ai']['source'] : '' ) ) {
				$ids[] = $row['id'];
			}
		}
		if ( ! $ids ) {
			return $this->respond( array( 'job' => null ) );
		}
		$job = Jobs::create( Entry_Insights::JOB, $ids, array(), 5 );
		return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ), 201 );
	}

	/**
	 * POST /entries/{id}/reply-draft { tone, notes }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply_draft( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return $this->respond( Auto_Reply::draft( (int) $request['id'], isset( $params['tone'] ) ? (string) $params['tone'] : 'friendly', isset( $params['notes'] ) ? sanitize_textarea_field( (string) $params['notes'] ) : '' ) );
	}

	/**
	 * POST /entries/{id}/reply { subject, body }: sends the approved reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = Auto_Reply::send( (int) $request['id'], isset( $params['subject'] ) ? (string) $params['subject'] : '', isset( $params['body'] ) ? (string) $params['body'] : '' );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		list( $entry, $form ) = self::load( $request );
		return $this->respond( self::detail( $entry, $form ) );
	}

	/**
	 * POST /entries/{id}/resend { what: [notifications, webhooks, integrations] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resend( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = Dispatcher::resend( (int) $request['id'], isset( $params['what'] ) ? (array) $params['what'] : array( 'notifications' ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		list( $entry, $form ) = self::load( $request );
		return $this->respond( array_merge( self::detail( $entry, $form ), array( 'summary' => $result ) ) );
	}
}
