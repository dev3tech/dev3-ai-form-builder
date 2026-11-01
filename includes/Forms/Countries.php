<?php
/**
 * ISO 3166-1 country list (countries.json; shared with the builder preview).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Countries.
 */
class Countries {

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $list = null;

	/**
	 * Code => name.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$list ) {
			$json = json_decode( (string) file_get_contents( __DIR__ . '/countries.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			/**
			 * Filters the country list (code => name).
			 *
			 * @param array $countries Countries.
			 */
			self::$list = (array) apply_filters( 'dev3fb_countries', is_array( $json ) ? $json : array() );
		}
		return self::$list;
	}

	/**
	 * Options for a country field.
	 *
	 * @return array [ { value, label } ]
	 */
	public static function options() {
		$out = array();
		foreach ( self::all() as $code => $name ) {
			$out[] = array(
				'value' => (string) $code,
				'label' => (string) $name,
			);
		}
		return $out;
	}

	/**
	 * Code for a code or name ('' when unknown).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function code( $value ) {
		$value = trim( (string) $value );
		$all   = self::all();
		if ( isset( $all[ strtoupper( $value ) ] ) ) {
			return strtoupper( $value );
		}
		foreach ( $all as $code => $name ) {
			if ( 0 === strcasecmp( $name, $value ) ) {
				return (string) $code;
			}
		}
		return '';
	}
}
