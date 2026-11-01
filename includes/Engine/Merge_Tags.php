<?php
/**
 * Merge tags for notifications, confirmations, webhooks and default values.
 *
 * Entry tags: {field:<id>}, {all_fields}, {form_title}, {form_id}, {entry_id}, {entry_url},
 * {payment_total}, {payment_status}, {site_name}, {site_url}, {admin_email}, {date}, {page_url}.
 * Default-value tags (rendering): {user:email|display_name|first_name|last_name|login},
 * {query:<param>}, {date}, {page_title}.
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Engine;

use Dev3\AiFormBuilder\Forms\Definition;
use Dev3\AiFormBuilder\Submission\Entry_Format;

defined( 'ABSPATH' ) || exit;

/**
 * Merge tags.
 */
class Merge_Tags {

	/**
	 * Replaces entry tags.
	 *
	 * @param string $text    Text.
	 * @param array  $context { definition, data, entry_id, form_id, form_title, total, payment_status, page_url }.
	 * @param string $mode    html|text|raw|url (escaping of field values).
	 * @return string
	 */
	public static function replace( $text, array $context, $mode = 'text' ) {
		$definition = isset( $context['definition'] ) ? $context['definition'] : array( 'steps' => array() );
		$data       = isset( $context['data'] ) ? (array) $context['data'] : array();
		$escape     = static function ( $value ) use ( $mode ) {
			if ( 'url' === $mode ) {
				return rawurlencode( (string) $value );
			}
			return 'html' === $mode ? nl2br( esc_html( $value ) ) : $value;
		};
		$text       = (string) preg_replace_callback(
			'/\{field:([a-z0-9_\-]+)\}/i',
			static function ( $m ) use ( $definition, $data, $escape ) {
				$field = Definition::field( $definition, $m[1] );
				if ( ! $field ) {
					return '';
				}
				return $escape( Entry_Format::display( $field, isset( $data[ $field['id'] ] ) ? $data[ $field['id'] ] : '' ) );
			},
			(string) $text
		);
		$entry_id   = isset( $context['entry_id'] ) ? (int) $context['entry_id'] : 0;
		$map        = array(
			'{form_title}'     => isset( $context['form_title'] ) ? $escape( $context['form_title'] ) : '',
			'{form_id}'        => isset( $context['form_id'] ) ? (string) (int) $context['form_id'] : '',
			'{entry_id}'       => (string) $entry_id,
			'{entry_url}'      => $entry_id ? admin_url( 'admin.php?page=dev3-ai-form-builder#/entries/' . $entry_id ) : '',
			'{payment_total}'  => isset( $context['total'] ) ? number_format_i18n( (float) $context['total'], 2 ) : '',
			'{payment_status}' => isset( $context['payment_status'] ) ? $escape( $context['payment_status'] ) : '',
			'{site_name}'      => $escape( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
			'{site_url}'       => home_url( '/' ),
			'{admin_email}'    => (string) get_option( 'admin_email' ),
			'{date}'           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'{page_url}'       => isset( $context['page_url'] ) ? esc_url_raw( $context['page_url'] ) : '',
		);
		if ( false !== strpos( $text, '{all_fields}' ) ) {
			$map['{all_fields}'] = 'html' === $mode ? Entry_Format::html( $definition, $data ) : Entry_Format::plain( $definition, $data );
		}
		return strtr( $text, $map );
	}

	/**
	 * Resolves default-value tags at render time.
	 *
	 * @param string $text Default value.
	 * @return string
	 */
	public static function defaults( $text ) {
		$text = (string) $text;
		if ( false === strpos( $text, '{' ) ) {
			return $text;
		}
		$user = wp_get_current_user();
		$text = (string) preg_replace_callback(
			'/\{user:([a-z_]+)\}/',
			static function ( $m ) use ( $user ) {
				if ( ! $user || ! $user->exists() ) {
					return '';
				}
				switch ( $m[1] ) {
					case 'email':
						return $user->user_email;
					case 'display_name':
						return $user->display_name;
					case 'first_name':
						return (string) $user->first_name;
					case 'last_name':
						return (string) $user->last_name;
					case 'login':
						return $user->user_login;
				}
				return '';
			},
			$text
		);
		$text = (string) preg_replace_callback(
			'/\{query:([a-zA-Z0-9_\-]+)\}/',
			static function ( $m ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill from the URL.
				return isset( $_GET[ $m[1] ] ) && is_string( $_GET[ $m[1] ] ) ? sanitize_text_field( wp_unslash( $_GET[ $m[1] ] ) ) : '';
			},
			$text
		);
		return strtr(
			$text,
			array(
				'{date}'       => wp_date( 'Y-m-d' ),
				'{page_title}' => is_singular() ? wp_strip_all_tags( get_the_title() ) : '',
			)
		);
	}
}
