<?php
/**
 * Test-only setup (not overwritten by sync-core).
 *
 * @package Dev3\AiFormBuilder
 */

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@example.com';
	}
);
spl_autoload_register(
	static function ( $class_name ) {
		if ( 'Dev3fb_TestCase' === $class_name ) {
			require_once __DIR__ . '/Dev3fb_TestCase.php';
		}
	}
);
