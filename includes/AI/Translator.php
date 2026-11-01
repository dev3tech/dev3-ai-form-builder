<?php
/**
 * Smart translation of a form's visible text and messages into another language.
 * Returns a draft map (key => text); the admin reviews/edits it and accepting stores it in
 * definition.translations[lang]. The form then renders in that language for visitors whose
 * site language matches (or with [dev3fb_form id="…" lang="de"]).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\AI;

use Dev3\AiFormBuilder\Core3\Api\Prompts;
use Dev3\AiFormBuilder\Forms\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Translator.
 */
class Translator {

	/**
	 * Translates.
	 *
	 * @param array  $definition Definition.
	 * @param string $lang       Target language code.
	 * @return array|\WP_Error { lang, strings: [ { key, source, text } ], notes, model, tokens }
	 */
	public static function translate( array $definition, $lang ) {
		$lang = Definition::lang_code( $lang );
		if ( '' === $lang ) {
			return new \WP_Error( 'dev3fb_bad_lang', __( 'Pick a language.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$source = Definition::strings( $definition );
		if ( ! $source ) {
			return new \WP_Error( 'dev3fb_nothing', __( 'This form has no text to translate yet.', 'dev3-ai-form-builder' ), array( 'status' => 400 ) );
		}
		$source = array_slice( $source, 0, 400, true );
		$result = Prompts::run(
			'translate',
			array(
				'language' => Form_Generator::language_name( $lang ) . " ({$lang})",
				'strings'  => $source,
			),
			'admin',
			array(
				'schema'     => self::schema( array_keys( $source ) ),
				'max_tokens' => 16000,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = isset( $result['data']['translations'] ) && is_array( $result['data']['translations'] ) ? $result['data']['translations'] : array();
		$out   = self::merge( $source, $items, $notes );
		return array(
			'lang'    => $lang,
			'strings' => $out,
			'notes'   => $notes,
			'model'   => isset( $result['model'] ) ? $result['model'] : '',
			'tokens'  => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * Schema with the keys as an enum.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public static function schema( array $keys ) {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'translations' => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'key'  => array(
								'type' => 'STRING',
								'enum' => array_values( $keys ),
							),
							'text' => array(
								'type'      => 'STRING',
								'maxLength' => 2000,
							),
						),
						'required'   => array( 'key', 'text' ),
					),
				),
			),
			'required'   => array( 'translations' ),
		);
	}

	/**
	 * Merges AI items over the source strings; keeps the source where the AI dropped a key or
	 * broke a merge tag.
	 *
	 * @param array      $source Source key => text.
	 * @param array      $items  AI items.
	 * @param array|null $notes  Receives notes.
	 * @return array [ { key, source, text } ]
	 */
	public static function merge( array $source, array $items, &$notes = null ) {
		$notes = array();
		$got   = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['key'], $item['text'] ) && isset( $source[ $item['key'] ] ) && is_scalar( $item['text'] ) ) {
				$got[ $item['key'] ] = (string) $item['text'];
			}
		}
		$out     = array();
		$missing = 0;
		foreach ( $source as $key => $text ) {
			$translated = isset( $got[ $key ] ) ? trim( $got[ $key ] ) : '';
			if ( '' === $translated ) {
				++$missing;
				$translated = $text;
			} else {
				preg_match_all( '/\{[a-z_]+(:[a-z0-9_\-]+)?\}/i', $text, $tags );
				foreach ( $tags[0] as $tag ) {
					if ( false === strpos( $translated, $tag ) ) {
						/* translators: %s: key */
						$notes[]    = sprintf( __( 'Kept the original text for %s because a merge tag was lost.', 'dev3-ai-form-builder' ), $key );
						$translated = $text;
						break;
					}
				}
			}
			$out[] = array(
				'key'    => $key,
				'source' => $text,
				'text'   => Definition::is_html_key( $key ) ? wp_kses_post( $translated ) : sanitize_text_field( $translated ),
			);
		}
		if ( $missing ) {
			/* translators: %d: count */
			$notes[] = sprintf( _n( '%d string was not translated and keeps the original text.', '%d strings were not translated and keep the original text.', $missing, 'dev3-ai-form-builder' ), $missing );
		}
		return $out;
	}
}
