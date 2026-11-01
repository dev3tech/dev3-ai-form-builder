<?php
/**
 * The submission pipeline (REST/AJAX and no-JS posts share it).
 *
 * Steps: availability → protection pre-checks → sanitize → logic → validate (+ files) → calculations
 * → AI spam shield → store files + entry → payment redirect, or delivery → confirmation.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Submission;

use Dev3\AiFormBuilder\AI\Entry_Insights;
use Dev3\AiFormBuilder\Core3\Core\Jobs;
use Dev3\AiFormBuilder\Core3\Core\Rate_Limiter;
use Dev3\AiFormBuilder\Core3\Core\Settings_Store;
use Dev3\AiFormBuilder\Data\Analytics;
use Dev3\AiFormBuilder\Data\Drafts;
use Dev3\AiFormBuilder\Data\Entries;
use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Delivery\Dispatcher;
use Dev3\AiFormBuilder\Engine\Calculator;
use Dev3\AiFormBuilder\Engine\Logic;
use Dev3\AiFormBuilder\Engine\Merge_Tags;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Forms\Form_Repository;
use Dev3\AiFormBuilder\Payments\Payments;
use Dev3\AiFormBuilder\Spam\Spam_Gate;
use Dev3\AiFormBuilder\Spam\Spam_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Handler.
 */
class Submission_Handler {

	/**
	 * Whether a form accepts submissions right now.
	 *
	 * @param array    $form Form.
	 * @param int|null $now  Clock (site-local timestamp comparisons use wp_timezone).
	 * @return true|\WP_Error
	 */
	public static function availability( array $form, $now = null ) {
		$settings = $form['definition']['settings'];
		$now      = null === $now ? time() : (int) $now;
		if ( 'active' !== $form['status'] && ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'dev3fb_closed', $settings['closed_message'], array( 'status' => 403 ) );
		}
		if ( $settings['require_login'] && ! is_user_logged_in() ) {
			return new \WP_Error( 'dev3fb_login', $settings['login_message'], array( 'status' => 403 ) );
		}
		$tz = wp_timezone();
		if ( '' !== $settings['open_at'] ) {
			$open = date_create( $settings['open_at'], $tz );
			if ( $open && $now < $open->getTimestamp() ) {
				return new \WP_Error( 'dev3fb_closed', $settings['closed_message'], array( 'status' => 403 ) );
			}
		}
		if ( '' !== $settings['close_at'] ) {
			$close = date_create( $settings['close_at'], $tz );
			if ( $close && $now >= $close->getTimestamp() ) {
				return new \WP_Error( 'dev3fb_closed', $settings['closed_message'], array( 'status' => 403 ) );
			}
		}
		if ( $settings['limit_entries'] > 0 && Entries::count_for_form( $form['id'] ) >= $settings['limit_entries'] ) {
			return new \WP_Error( 'dev3fb_closed', $settings['closed_message'], array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Sanitizes every input field from request params.
	 *
	 * @param array $definition Definition.
	 * @param array $input      The dev3fb[...] array.
	 * @return array field id => value
	 */
	public static function collect( array $definition, array $input ) {
		$values = array();
		foreach ( Definition::input_fields( $definition, false ) as $field ) {
			if ( in_array( $field['type'], array( 'file', 'signature' ), true ) ) {
				continue;
			}
			if ( 'product' === $field['type'] && empty( $field['options'] ) ) {
				$values[ $field['id'] ] = '1';
				continue;
			}
			$values[ $field['id'] ] = Validator::sanitize( $field, isset( $input[ $field['id'] ] ) ? $input[ $field['id'] ] : '' );
		}
		return $values;
	}

	/**
	 * Validates values (and files). Returns field id => message.
	 *
	 * @param array $definition Definition.
	 * @param array $values     Values (hidden fields blanked).
	 * @param array $visible    Visibility map.
	 * @param array $files      Field id => normalized files.
	 * @param array $input      Raw input (signatures).
	 * @return array
	 */
	public static function validate( array $definition, array $values, array $visible, array $files, array $input ) {
		$errors   = array();
		$messages = array();
		$lang     = isset( $definition['lang'] ) ? $definition['lang'] : '';
		if ( '' !== $lang && isset( $definition['translations'][ $lang ] ) ) {
			foreach ( $definition['translations'][ $lang ] as $key => $text ) {
				if ( 0 === strpos( $key, 'message.' ) ) {
					$messages[ substr( $key, 8 ) ] = $text;
				}
			}
		}
		foreach ( Definition::input_fields( $definition, false ) as $field ) {
			if ( isset( $visible[ $field['id'] ] ) && ! $visible[ $field['id'] ] ) {
				continue;
			}
			if ( 'file' === $field['type'] ) {
				$error = Uploads::validate( $field, isset( $files[ $field['id'] ] ) ? $files[ $field['id'] ] : array() );
			} elseif ( 'signature' === $field['type'] ) {
				$raw   = isset( $input[ $field['id'] ] ) && is_string( $input[ $field['id'] ] ) ? $input[ $field['id'] ] : '';
				$error = '' === $raw && $field['required'] ? Validator::messages()['required'] : '';
			} else {
				$error = Validator::validate( $field, isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '', $messages );
			}
			if ( '' !== $error ) {
				$errors[ $field['id'] ] = $error;
			}
		}
		/**
		 * Filters validation errors (add your own rules).
		 *
		 * @param array $errors     Field id => message.
		 * @param array $values     Values.
		 * @param array $definition Definition.
		 */
		return (array) apply_filters( 'dev3fb_validate', $errors, $values, $definition );
	}

	/**
	 * Index of the step holding a field.
	 *
	 * @param array  $definition Definition.
	 * @param string $field_id   Field id.
	 * @return int
	 */
	public static function step_of( array $definition, $field_id ) {
		foreach ( $definition['steps'] as $i => $step ) {
			foreach ( $step['fields'] as $field ) {
				if ( $field['id'] === $field_id ) {
					return (int) $i;
				}
			}
		}
		return 0;
	}

	/**
	 * Picks the confirmation.
	 *
	 * @param array $form    Form (translated definition).
	 * @param array $entry   Entry (or a stub with data).
	 * @return array { type, message, url }
	 */
	public static function confirmation( array $form, array $entry ) {
		$context = Dispatcher::context( $form, $entry );
		foreach ( $form['definition']['confirmations'] as $confirmation ) {
			if ( ! Logic::visible( $confirmation['logic'], $entry['data'] ) ) {
				continue;
			}
			if ( 'redirect' === $confirmation['type'] && '' !== $confirmation['url'] ) {
				return array(
					'type'    => 'redirect',
					'message' => '',
					'url'     => esc_url_raw( Merge_Tags::replace( $confirmation['url'], $context, 'url' ) ),
				);
			}
			if ( 'page' === $confirmation['type'] && $confirmation['page_id'] && get_permalink( $confirmation['page_id'] ) ) {
				return array(
					'type'    => 'redirect',
					'message' => '',
					'url'     => (string) get_permalink( $confirmation['page_id'] ),
				);
			}
			return array(
				'type'    => 'message',
				'message' => wp_kses_post( wpautop( Merge_Tags::replace( $confirmation['message'], $context, 'html' ) ) ),
				'url'     => '',
			);
		}
		return array(
			'type'    => 'message',
			'message' => '<p>' . esc_html__( 'Thanks! Your submission has been received.', 'dev3-ai-form-builder' ) . '</p>',
			'url'     => '',
		);
	}

	/**
	 * Handles a submission.
	 *
	 * @param int   $form_id Form id.
	 * @param array $params  Request params: dev3fb (values), dev3fb_token, dev3fb_hp, dev3fb_lang,
	 *                       dev3fb_page, dev3fb_session, dev3fb_resume, dev3fb_provider.
	 * @param array $files   $_FILES-style array.
	 * @return array { ok, status (HTTP), code, message, errors, step, confirmation, entry_id, redirect }
	 */
	public static function handle( $form_id, array $params, array $files = array() ) {
		$form = Form_Repository::get( $form_id );
		if ( ! $form ) {
			return self::fail( 404, 'dev3fb_unknown_form', __( 'This form no longer exists.', 'dev3-ai-form-builder' ) );
		}
		$lang = isset( $params['dev3fb_lang'] ) ? Definition::lang_code( $params['dev3fb_lang'] ) : '';
		if ( '' !== $lang ) {
			$form['definition'] = Definition::translate( $form['definition'], $lang );
		}
		$definition = $form['definition'];

		$open = self::availability( $form );
		if ( is_wp_error( $open ) ) {
			return self::fail( 403, $open->get_error_code(), $open->get_error_message() );
		}

		$issued   = Form_Token::verify( isset( $params['dev3fb_token'] ) ? (string) $params['dev3fb_token'] : '', $form['id'] );
		$decision = Spam_Gate::precheck( $form, $params, $issued );
		if ( $decision ) {
			if ( ! empty( $decision['silent'] ) ) {
				// Bots get a normal-looking success; nothing is stored or sent.
				return array_merge(
					self::ok(),
					array(
						'confirmation' => self::confirmation(
							$form,
							array(
								'id'             => 0,
								'data'           => array(),
								'payment_total'  => 0,
								'payment_status' => '',
								'meta'           => array(),
							)
						),
					)
				);
			}
			return self::fail( 429, 'dev3fb_spam_blocked', '' !== $decision['message'] ? $decision['message'] : __( 'Too many submissions. Please wait a moment.', 'dev3-ai-form-builder' ) );
		}
		if ( is_wp_error( $issued ) ) {
			return self::fail( 403, $issued->get_error_code(), $issued->get_error_message() );
		}

		$input  = isset( $params['dev3fb'] ) && is_array( $params['dev3fb'] ) ? $params['dev3fb'] : array();
		$values = self::collect( $definition, $input );
		$logic  = Logic::evaluate( $definition, $values );
		$values = $logic['values'];

		$uploads = array();
		foreach ( Definition::fields( $definition ) as $field ) {
			if ( 'file' === $field['type'] ) {
				$uploads[ $field['id'] ] = Uploads::normalize_files( isset( $files[ 'dev3fb_files_' . $field['id'] ] ) ? $files[ 'dev3fb_files_' . $field['id'] ] : null );
			}
		}
		$errors = self::validate( $definition, $values, $logic['fields'], $uploads, $input );
		if ( $errors ) {
			$first = array_keys( $errors )[0];
			return array_merge(
				self::fail( 422, 'dev3fb_invalid', __( 'Please fix the highlighted fields.', 'dev3-ai-form-builder' ) ),
				array(
					'errors' => $errors,
					'step'   => self::step_of( $definition, $first ),
				)
			);
		}

		$calc   = Calculator::run( $definition, $values, $logic['fields'] );
		$values = $calc['values'];

		$spam = Spam_Gate::check( $form, $values );
		if ( 'block' === $spam['action'] && $spam['triggered'] ) {
			return self::fail( 422, 'dev3fb_spam_blocked', '' !== $spam['message'] ? $spam['message'] : __( 'Your submission could not be sent.', 'dev3-ai-form-builder' ) );
		}

		// Files and signatures (only after everything validated).
		foreach ( Definition::fields( $definition ) as $field ) {
			if ( isset( $logic['fields'][ $field['id'] ] ) && ! $logic['fields'][ $field['id'] ] ) {
				continue;
			}
			if ( 'file' === $field['type'] && ! empty( $uploads[ $field['id'] ] ) ) {
				$values[ $field['id'] ] = Uploads::store( $uploads[ $field['id'] ] );
			} elseif ( 'signature' === $field['type'] && ! empty( $input[ $field['id'] ] ) ) {
				$saved                  = Uploads::store_signature( (string) $input[ $field['id'] ] );
				$values[ $field['id'] ] = $saved ? $saved : '';
			} elseif ( 'password' === $field['type'] && '' !== $values[ $field['id'] ] ) {
				$values[ $field['id'] ] = wp_hash_password( $values[ $field['id'] ] );
			}
		}

		$email_field = Definition::email_field( $definition );
		$payments    = $definition['payments'];
		$amount      = '' !== $payments['amount_field'] && isset( $values[ $payments['amount_field'] ] ) ? round( max( 0, (float) $values[ $payments['amount_field'] ] ), 2 ) : $calc['total'];
		$pay         = $payments['enabled'] && $amount > 0 && 'spam' !== $spam['action'];
		$provider    = isset( $params['dev3fb_provider'] ) && in_array( $params['dev3fb_provider'], $payments['providers'], true ) ? (string) $params['dev3fb_provider'] : $payments['providers'][0];

		$is_spam = $spam['triggered'] && 'spam' === $spam['action'];
		$meta    = array(
			'page_url'   => isset( $params['dev3fb_page'] ) ? esc_url_raw( (string) $params['dev3fb_page'] ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '',
			'lines'      => $calc['lines'],
		);
		if ( Settings_Store::get( 'store_ip', false ) ) {
			$meta['ip'] = Rate_Limiter::client_ip();
		}
		if ( $spam['triggered'] ) {
			$meta['spam'] = array(
				'action'  => $spam['action'],
				'trigger' => $spam['trigger'],
				'reason'  => $spam['reason'],
				'score'   => isset( $spam['verdict']['spam_probability'] ) ? (int) round( $spam['verdict']['spam_probability'] * 100 ) : 0,
			);
		}
		$row = array(
			'form_id'          => $form['id'],
			'status'           => $is_spam ? 'spam' : ( $pay ? 'pending' : 'unread' ),
			'data'             => $values,
			'meta'             => $meta,
			'email'            => '' !== $email_field && is_string( $values[ $email_field ] ) ? $values[ $email_field ] : '',
			'lang'             => '' !== $lang ? $lang : ( isset( $spam['verdict']['language'] ) ? (string) $spam['verdict']['language'] : '' ),
			'payment_status'   => $pay ? 'pending' : '',
			'payment_total'    => $amount,
			'payment_currency' => $payments['enabled'] ? $payments['currency'] : '',
			'sentiment'        => isset( $spam['verdict']['sentiment'] ) ? (string) $spam['verdict']['sentiment'] : '',
		);
		/**
		 * Filters the entry row before it is stored.
		 *
		 * @param array $row  Row.
		 * @param array $form Form.
		 */
		$row = (array) apply_filters( 'dev3fb_entry_row', $row, $form );

		$store    = $definition['settings']['store_entries'] || $pay;
		$entry_id = $store ? Entries::insert( $row ) : 0;
		$entry    = $entry_id ? Entries::get( $entry_id ) : array_merge(
			$row,
			array(
				'id'               => 0,
				'created_at'       => gmdate( 'c' ),
				'payment_provider' => '',
				'payment_ref'      => '',
				'ai'               => null,
				'starred'          => false,
				'user_id'          => get_current_user_id(),
			)
		);
		if ( $spam['triggered'] && $spam['log_id'] ) {
			Spam_Log::update( $spam['log_id'], array( 'entry_id' => $entry_id ) );
		}
		if ( $entry_id && $spam['triggered'] ) {
			Entry_Log::add( $entry_id, 'spam', 'info', sprintf( 'Spam shield (%s): %s', $spam['trigger'], $spam['reason'] ) );
		}

		if ( ! empty( $params['dev3fb_resume'] ) ) {
			Drafts::delete( (string) $params['dev3fb_resume'], $form['id'] );
		}
		if ( ! empty( $params['dev3fb_session'] ) && Settings_Store::get( 'analytics', true ) ) {
			Analytics::record( $form['id'], (string) $params['dev3fb_session'], 'submit', count( $definition['steps'] ) );
		}

		/**
		 * Fires after an entry is stored (before payment/delivery).
		 *
		 * @param array $entry Entry.
		 * @param array $form  Form.
		 */
		do_action( 'dev3fb_entry_created', $entry, $form );

		if ( $is_spam ) {
			return array_merge( self::ok( $entry_id ), array( 'confirmation' => self::confirmation( $form, $entry ) ) );
		}

		if ( $pay ) {
			$redirect = Payments::start( $form, $entry, $provider, $meta['page_url'] );
			if ( is_wp_error( $redirect ) ) {
				$data = $redirect->get_error_data();
				return self::fail( is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502, $redirect->get_error_code(), $redirect->get_error_message() );
			}
			return array_merge(
				self::ok( $entry_id ),
				array(
					'redirect'     => $redirect,
					'confirmation' => array(
						'type'    => 'redirect',
						'message' => '',
						'url'     => $redirect,
					),
				)
			);
		}

		Dispatcher::deliver( $form, $entry );
		if ( $entry_id && Settings_Store::get( 'auto_analyze', false ) && '' !== Settings_Store::api_key() ) {
			Jobs::create( Entry_Insights::JOB, array( $entry_id ), array(), 1 );
		}
		return array_merge( self::ok( $entry_id ), array( 'confirmation' => self::confirmation( $form, $entry ) ) );
	}

	/**
	 * Success shape.
	 *
	 * @param int $entry_id Entry id.
	 * @return array
	 */
	private static function ok( $entry_id = 0 ) {
		return array(
			'ok'           => true,
			'status'       => 200,
			'code'         => 'ok',
			'message'      => '',
			'errors'       => array(),
			'step'         => 0,
			'entry_id'     => (int) $entry_id,
			'redirect'     => '',
			'confirmation' => null,
		);
	}

	/**
	 * Failure shape.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function fail( $status, $code, $message ) {
		return array(
			'ok'           => false,
			'status'       => (int) $status,
			'code'         => (string) $code,
			'message'      => (string) $message,
			'errors'       => array(),
			'step'         => 0,
			'entry_id'     => 0,
			'redirect'     => '',
			'confirmation' => null,
		);
	}
}
