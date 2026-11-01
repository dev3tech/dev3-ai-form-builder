<?php
/**
 * File uploads and signatures. Files live in uploads/dev3fb/<random>/ behind deny rules and are
 * only served to managers through the REST download route (never by a public URL).
 *
 * @package Dev3\AiFormBuilder
 */

namespace Dev3\AiFormBuilder\Submission;

use Dev3\AiFormBuilder\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads.
 */
class Uploads {

	const BLOCKED = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'bash', 'exe', 'msi', 'bat', 'cmd', 'com', 'dll', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'htaccess', 'ini', 'swf' );

	/**
	 * Base directory (created and protected on first use).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'dev3fb';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
		return $dir;
	}

	/**
	 * Normalizes $_FILES-style arrays for one input into a list of files.
	 *
	 * @param array|null $raw $_FILES[ name ].
	 * @return array [ { name, type, tmp_name, error, size } ]
	 */
	public static function normalize_files( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['name'] ) ) {
			return array();
		}
		if ( ! is_array( $raw['name'] ) ) {
			return UPLOAD_ERR_NO_FILE === (int) $raw['error'] ? array() : array( $raw );
		}
		$out = array();
		foreach ( $raw['name'] as $i => $name ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $raw['type'][ $i ],
				'tmp_name' => $raw['tmp_name'][ $i ],
				'error'    => $raw['error'][ $i ],
				'size'     => $raw['size'][ $i ],
			);
		}
		return $out;
	}

	/**
	 * Validates files for a field. Returns an error message or ''.
	 *
	 * @param array $field Field.
	 * @param array $files Normalized files.
	 * @return string
	 */
	public static function validate( array $field, array $files ) {
		if ( ! $files ) {
			return ! empty( $field['required'] ) ? Validator::messages()['required'] : '';
		}
		$max_files = isset( $field['max_files'] ) ? (int) $field['max_files'] : 1;
		if ( count( $files ) > $max_files ) {
			/* translators: %d: max files */
			return sprintf( _n( 'You can upload %d file.', 'You can upload up to %d files.', $max_files, 'dev3-ai-form-builder' ), $max_files );
		}
		$max_mb  = min( (float) ( isset( $field['max_size'] ) ? $field['max_size'] : 5 ), (float) Settings_Store::get( 'upload_max_mb', 10 ) );
		$allowed = array_filter( explode( ',', isset( $field['accept'] ) ? (string) $field['accept'] : '' ) );
		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				return __( 'The file could not be uploaded. Try again.', 'dev3-ai-form-builder' );
			}
			if ( (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
				/* translators: %s: size in MB */
				return sprintf( __( 'Files must be smaller than %s MB.', 'dev3-ai-form-builder' ), number_format_i18n( $max_mb, $max_mb < 1 ? 1 : 0 ) );
			}
			$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			if ( '' === $ext || in_array( $ext, self::BLOCKED, true ) || ( $allowed && ! in_array( $ext, $allowed, true ) ) ) {
				/* translators: %s: allowed extensions */
				return sprintf( __( 'Allowed file types: %s.', 'dev3-ai-form-builder' ), $allowed ? implode( ', ', $allowed ) : '-' );
			}
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $check['ext'] ) && ! apply_filters( 'dev3fb_allow_unknown_mime', false, $file ) ) {
				return __( 'This file type is not allowed.', 'dev3-ai-form-builder' );
			}
		}
		return '';
	}

	/**
	 * Stores validated files.
	 *
	 * @param array $files Normalized files.
	 * @return array [ { name, path, size, type } ] (path relative to the dev3fb folder).
	 */
	public static function store( array $files ) {
		$base = self::base_dir();
		$sub  = gmdate( 'Y/m' ) . '/' . strtolower( wp_generate_password( 16, false, false ) );
		wp_mkdir_p( $base . '/' . $sub );
		$out = array();
		foreach ( $files as $file ) {
			$name   = sanitize_file_name( wp_basename( (string) $file['name'] ) );
			$name   = '' === $name ? 'file' : $name;
			$target = $base . '/' . $sub . '/' . wp_unique_filename( $base . '/' . $sub, $name );
			$moved  = is_uploaded_file( $file['tmp_name'] )
				? move_uploaded_file( $file['tmp_name'], $target )
				/**
				 * Allows copying files that did not come from an HTTP upload (tests, imports).
				 *
				 * @param bool $allow Allow.
				 */
				: ( apply_filters( 'dev3fb_allow_sideload', false ) && copy( $file['tmp_name'], $target ) );
			if ( ! $moved ) {
				continue;
			}
			$out[] = array(
				'name' => $name,
				'path' => $sub . '/' . wp_basename( $target ),
				'size' => (int) filesize( $target ),
				'type' => (string) wp_check_filetype( $target )['type'],
			);
		}
		return $out;
	}

	/**
	 * Saves a signature data URL as a PNG.
	 *
	 * @param string $data_url data:image/png;base64,...
	 * @return array|null { name, path, size, type }
	 */
	public static function store_signature( $data_url ) {
		if ( ! is_string( $data_url ) || 0 !== strpos( $data_url, 'data:image/png;base64,' ) || strlen( $data_url ) > 2 * MB_IN_BYTES ) {
			return null;
		}
		$bin = base64_decode( substr( $data_url, 22 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bin || "\x89PNG" !== substr( $bin, 0, 4 ) || strlen( $bin ) < 100 ) {
			return null;
		}
		$base = self::base_dir();
		$sub  = gmdate( 'Y/m' ) . '/' . strtolower( wp_generate_password( 16, false, false ) );
		wp_mkdir_p( $base . '/' . $sub );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $base . '/' . $sub . '/signature.png', $bin ) ) {
			return null;
		}
		return array(
			'name' => 'signature.png',
			'path' => $sub . '/signature.png',
			'size' => strlen( $bin ),
			'type' => 'image/png',
		);
	}

	/**
	 * Absolute path of a stored file (null if outside the folder).
	 *
	 * @param string $relative Relative path.
	 * @return string|null
	 */
	public static function absolute( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return null;
		}
		$path = self::base_dir() . '/' . $relative;
		return is_file( $path ) ? $path : null;
	}

	/**
	 * Every stored file of an entry: [ { field, index, name, path, size, type } ].
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function files_of( array $entry ) {
		$out = array();
		foreach ( $entry['data'] as $field_id => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( isset( $value['path'] ) ) {
				$out[] = array_merge(
					$value,
					array(
						'field' => $field_id,
						'index' => 0,
					)
				);
				continue;
			}
			foreach ( array_values( $value ) as $i => $file ) {
				if ( is_array( $file ) && isset( $file['path'], $file['name'] ) ) {
					$out[] = array_merge(
						$file,
						array(
							'field' => $field_id,
							'index' => $i,
						)
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Deletes an entry's files.
	 *
	 * @param array $entry Entry.
	 */
	public static function delete_for_entry( array $entry ) {
		foreach ( self::files_of( $entry ) as $file ) {
			$path = self::absolute( $file['path'] );
			if ( $path ) {
				wp_delete_file( $path );
				@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}
}
