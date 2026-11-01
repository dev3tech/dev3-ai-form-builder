<?php
/**
 * Marketing/CRM integrations: Mailchimp, Brevo, HubSpot, Google Sheets (Apps Script web app)
 * and Zapier (catch hook). Credentials come from Secrets; field mapping is per form.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Delivery;

use Dev3\AiFormBuilder\Data\Entry_Log;
use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Secrets;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations.
 */
class Integrations {

	const SERVICES = array( 'mailchimp', 'brevo', 'hubspot', 'sheets', 'zapier' );

	/**
	 * Runs every enabled integration of a form.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array service => true|WP_Error
	 */
	public static function run_all( array $form, array $entry ) {
		$config  = $form['definition']['integrations'];
		$out     = array();
		$consent = $config['consent_field'];
		$allowed = '' === $consent || ! empty( $entry['data'][ $consent ] );
		foreach ( self::SERVICES as $service ) {
			if ( empty( $config[ $service ]['enabled'] ) ) {
				continue;
			}
			if ( ! $allowed && in_array( $service, array( 'mailchimp', 'brevo', 'hubspot' ), true ) ) {
				Entry_Log::add( $entry['id'], $service, 'info', 'Skipped: the visitor did not give consent.' );
				continue;
			}
			$result          = self::run( $service, $form, $entry );
			$out[ $service ] = $result;
			Entry_Log::add( $entry['id'], $service, is_wp_error( $result ) ? 'error' : 'ok', is_wp_error( $result ) ? $result->get_error_message() : __( 'Sent.', 'dev3-ai-form-builder' ) );
		}
		return $out;
	}

	/**
	 * One service.
	 *
	 * @param string $service Service.
	 * @param array  $form    Form.
	 * @param array  $entry   Entry.
	 * @return true|\WP_Error
	 */
	public static function run( $service, array $form, array $entry ) {
		$config = $form['definition']['integrations'][ $service ];
		switch ( $service ) {
			case 'mailchimp':
				return self::mailchimp( $config, self::contact( $form, $entry, $config['map'] ) );
			case 'brevo':
				return self::brevo( $config, self::contact( $form, $entry, $config['map'] ) );
			case 'hubspot':
				return self::hubspot( self::contact( $form, $entry, $config['map'] ) );
			case 'sheets':
				return self::post_json( $config['url'], self::sheet_row( $form, $entry ) );
			case 'zapier':
				return self::post_json( $config['url'], Webhooks::payload( $form, $entry ) );
		}
		return new \WP_Error( 'dev3fb_unknown_service', 'Unknown integration.' );
	}

	/**
	 * Mapped contact values (display strings). Name fields fill first/last automatically.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @param array $map   Key => field id.
	 * @return array
	 */
	public static function contact( array $form, array $entry, array $map ) {
		$out = array();
		foreach ( $map as $key => $field_id ) {
			$field = '' !== $field_id ? Definition::field( $form['definition'], $field_id ) : null;
			if ( ! $field ) {
				continue;
			}
			$value = isset( $entry['data'][ $field_id ] ) ? $entry['data'][ $field_id ] : '';
			if ( 'name' === $field['type'] && is_array( $value ) ) {
				$out[ $key ] = 'last_name' === $key ? $value['last'] : ( 'first_name' === $key ? $value['first'] : trim( $value['first'] . ' ' . $value['last'] ) );
				continue;
			}
			$out[ $key ] = Entry_Format::display( $field, $value );
		}
		if ( empty( $out['email'] ) && '' !== $entry['email'] ) {
			$out['email'] = $entry['email'];
		}
		return $out;
	}

	/**
	 * Google Sheets row: { sheet, submitted_at, entry_id, values: { label: display } }.
	 *
	 * @param array $form  Form.
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function sheet_row( array $form, array $entry ) {
		$values = array();
		foreach ( Entry_Format::rows( $form['definition'], $entry['data'], true ) as $row ) {
			if ( 'password' !== $row['type'] ) {
				$values[ $row['label'] ] = $row['value'];
			}
		}
		return array(
			'sheet'        => mb_substr( $form['title'], 0, 90 ),
			'entry_id'     => $entry['id'],
			'submitted_at' => $entry['created_at'],
			'values'       => $values,
		);
	}

	/**
	 * Interprets an HTTP response.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param string          $service  Service.
	 * @return array|\WP_Error Decoded body.
	 */
	private static function check( $response, $service ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $body ) ? $body : array();
		}
		$detail = '';
		if ( is_array( $body ) ) {
			foreach ( array( 'detail', 'message', 'title', 'error' ) as $key ) {
				if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
					$detail = $body[ $key ];
					break;
				}
			}
		}
		return new \WP_Error( 'dev3fb_' . $service . '_http', trim( sprintf( '%s HTTP %d %s', ucfirst( $service ), $code, $detail ) ), array( 'status' => $code ) );
	}

	/**
	 * Mailchimp data center from the key ("…-us21").
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mailchimp_dc( $key ) {
		$pos = strrpos( (string) $key, '-' );
		$dc  = false === $pos ? '' : substr( $key, $pos + 1 );
		return preg_match( '/^[a-z]{2,4}\d{1,3}$/', $dc ) ? $dc : '';
	}

	/**
	 * Mailchimp request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /3.0/.
	 * @param array|null $body   Body.
	 * @return array|\WP_Error
	 */
	public static function mailchimp_request( $method, $path, $body = null ) {
		$key = Secrets::get( 'mailchimp_key' );
		$dc  = self::mailchimp_dc( $key );
		if ( '' === $dc ) {
			return new \WP_Error( 'dev3fb_mailchimp_key', __( 'Add a Mailchimp API key (it ends in -usXX) in Settings → Integrations.', 'dev3-ai-form-builder' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'dev3:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::check( wp_remote_request( "https://{$dc}.api.mailchimp.com/3.0/" . ltrim( $path, '/' ), $args ), 'mailchimp' );
	}

	/**
	 * Mailchimp subscribe/update.
	 *
	 * @param array $config  Config.
	 * @param array $contact Contact.
	 * @return true|\WP_Error
	 */
	public static function mailchimp( array $config, array $contact ) {
		if ( empty( $contact['email'] ) || '' === $config['list_id'] ) {
			return new \WP_Error( 'dev3fb_mailchimp_config', __( 'Mailchimp: choose an audience and map the email field.', 'dev3-ai-form-builder' ) );
		}
		$hash   = md5( strtolower( $contact['email'] ) );
		$list   = rawurlencode( $config['list_id'] );
		$merge  = array_filter(
			array(
				'FNAME' => isset( $contact['first_name'] ) ? $contact['first_name'] : '',
				'LNAME' => isset( $contact['last_name'] ) ? $contact['last_name'] : '',
				'PHONE' => isset( $contact['phone'] ) ? $contact['phone'] : '',
			)
		);
		$result = self::mailchimp_request(
			'PUT',
			"lists/{$list}/members/{$hash}",
			array(
				'email_address' => $contact['email'],
				'status_if_new' => ! empty( $config['double'] ) ? 'pending' : 'subscribed',
				'merge_fields'  => (object) $merge,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$tags = array_filter( array_map( 'trim', explode( ',', $config['tags'] ) ) );
		if ( $tags ) {
			$payload = array();
			foreach ( $tags as $tag ) {
				$payload[] = array(
					'name'   => $tag,
					'status' => 'active',
				);
			}
			$tagged = self::mailchimp_request( 'POST', "lists/{$list}/members/{$hash}/tags", array( 'tags' => $payload ) );
			if ( is_wp_error( $tagged ) ) {
				return $tagged;
			}
		}
		return true;
	}

	/**
	 * Brevo request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /v3/.
	 * @param array|null $body   Body.
	 * @return array|\WP_Error
	 */
	public static function brevo_request( $method, $path, $body = null ) {
		$key = Secrets::get( 'brevo_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'dev3fb_brevo_key', __( 'Add a Brevo API key in Settings → Integrations.', 'dev3-ai-form-builder' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'api-key'      => $key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::check( wp_remote_request( 'https://api.brevo.com/v3/' . ltrim( $path, '/' ), $args ), 'brevo' );
	}

	/**
	 * Brevo create/update contact.
	 *
	 * @param array $config  Config.
	 * @param array $contact Contact.
	 * @return true|\WP_Error
	 */
	public static function brevo( array $config, array $contact ) {
		if ( empty( $contact['email'] ) ) {
			return new \WP_Error( 'dev3fb_brevo_config', __( 'Brevo: map the email field.', 'dev3-ai-form-builder' ) );
		}
		$body = array(
			'email'         => $contact['email'],
			'attributes'    => (object) array_filter(
				array(
					'FIRSTNAME' => isset( $contact['first_name'] ) ? $contact['first_name'] : '',
					'LASTNAME'  => isset( $contact['last_name'] ) ? $contact['last_name'] : '',
					'SMS'       => isset( $contact['phone'] ) ? preg_replace( '/[^\d+]/', '', $contact['phone'] ) : '',
				)
			),
			'updateEnabled' => true,
		);
		if ( '' !== $config['list_id'] && ctype_digit( $config['list_id'] ) ) {
			$body['listIds'] = array( (int) $config['list_id'] );
		}
		$result = self::brevo_request( 'POST', 'contacts', $body );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * HubSpot request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @return array|\WP_Error
	 */
	public static function hubspot_request( $method, $path, $body = null ) {
		$token = Secrets::get( 'hubspot_token' );
		if ( '' === $token ) {
			return new \WP_Error( 'dev3fb_hubspot_key', __( 'Add a HubSpot private app token in Settings → Integrations.', 'dev3-ai-form-builder' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::check( wp_remote_request( 'https://api.hubapi.com/' . ltrim( $path, '/' ), $args ), 'hubspot' );
	}

	/**
	 * HubSpot create (or update on conflict).
	 *
	 * @param array $contact Contact.
	 * @return true|\WP_Error
	 */
	public static function hubspot( array $contact ) {
		if ( empty( $contact['email'] ) ) {
			return new \WP_Error( 'dev3fb_hubspot_config', __( 'HubSpot: map the email field.', 'dev3-ai-form-builder' ) );
		}
		$keys       = array(
			'email'      => 'email',
			'first_name' => 'firstname',
			'last_name'  => 'lastname',
			'phone'      => 'phone',
			'company'    => 'company',
			'website'    => 'website',
			'message'    => 'message',
		);
		$properties = array();
		foreach ( $keys as $ours => $theirs ) {
			if ( ! empty( $contact[ $ours ] ) ) {
				$properties[ $theirs ] = $contact[ $ours ];
			}
		}
		$result = self::hubspot_request( 'POST', 'crm/v3/objects/contacts', array( 'properties' => $properties ) );
		if ( is_wp_error( $result ) && 409 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			unset( $properties['email'] );
			$result = self::hubspot_request( 'PATCH', 'crm/v3/objects/contacts/' . rawurlencode( $contact['email'] ) . '?idProperty=email', array( 'properties' => (object) $properties ) );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * JSON POST (Sheets, Zapier).
	 *
	 * @param string $url     URL.
	 * @param array  $payload Payload.
	 * @return true|\WP_Error
	 */
	public static function post_json( $url, array $payload ) {
		if ( '' === (string) $url ) {
			return new \WP_Error( 'dev3fb_no_url', __( 'Add the web app / webhook URL.', 'dev3-ai-form-builder' ) );
		}
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload ),
			)
		);
		$result   = self::check( $response, 'webhook' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Tests credentials and returns lists to pick from.
	 *
	 * @param string $service mailchimp|brevo|hubspot.
	 * @return array|\WP_Error { ok, message, lists: [ { value, label } ] }
	 */
	public static function test( $service ) {
		switch ( $service ) {
			case 'mailchimp':
				$result = self::mailchimp_request( 'GET', 'lists?count=100&fields=lists.id,lists.name' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$lists = array();
				foreach ( isset( $result['lists'] ) ? (array) $result['lists'] : array() as $list ) {
					$lists[] = array(
						'value' => (string) $list['id'],
						'label' => (string) $list['name'],
					);
				}
				return array(
					'ok'      => true,
					/* translators: %d: number of audiences */
					'message' => sprintf( __( 'Connected. %d audiences found.', 'dev3-ai-form-builder' ), count( $lists ) ),
					'lists'   => $lists,
				);
			case 'brevo':
				$result = self::brevo_request( 'GET', 'contacts/lists?limit=50' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$lists = array();
				foreach ( isset( $result['lists'] ) ? (array) $result['lists'] : array() as $list ) {
					$lists[] = array(
						'value' => (string) $list['id'],
						'label' => (string) $list['name'],
					);
				}
				return array(
					'ok'      => true,
					/* translators: %d: number of lists */
					'message' => sprintf( __( 'Connected. %d lists found.', 'dev3-ai-form-builder' ), count( $lists ) ),
					'lists'   => $lists,
				);
			case 'hubspot':
				$result = self::hubspot_request( 'GET', 'crm/v3/objects/contacts?limit=1' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'ok'      => true,
					'message' => __( 'Connected to HubSpot.', 'dev3-ai-form-builder' ),
					'lists'   => array(),
				);
		}
		return new \WP_Error( 'dev3fb_unknown_service', __( 'Unknown integration.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
	}
}
