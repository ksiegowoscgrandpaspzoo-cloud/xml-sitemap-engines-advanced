<?php
/**
 * PHPUnit bootstrap for the News Advanced add-on.
 *
 * Mirrors the free plugin's test-harness pattern: stubs WPINC + WP core
 * classes, loads relevant classes from both the add-on AND the free
 * plugin (add-on depends on free types like `XMLSE\Admin\Sanitize`
 * and `XMLSE\Admin\Sitemap_Settings`).
 *
 * Resolves the free plugin's source tree as a sibling directory. When
 * the two plugins live elsewhere (CI pinning, per-release testing) point
 * `XMLSE_FREE_DIR` at the target path before running PHPUnit.
 *
 * @package XMLSE_Advanced
 */

// Composer autoload (Brain\Monkey, PHPUnit polyfills).
if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}

// WPINC stub — the source files guard their top with `defined( 'WPINC' )`.
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// Resolve the free plugin directory (for loading its classes that this
// add-on depends on). Tries two sibling layouts used during development
// — override by defining XMLSE_FREE_DIR before including this file.
if ( ! defined( 'XMLSE_FREE_DIR' ) ) {
	$xmlse_candidates = array(
		__DIR__ . '/../../XML Sitemap/xml-sitemap-engines',
		__DIR__ . '/../../xml-sitemap-engines',
	);
	foreach ( $xmlse_candidates as $xmlse_candidate ) {
		$xmlse_resolved = realpath( $xmlse_candidate );
		if ( false !== $xmlse_resolved && is_dir( $xmlse_resolved ) ) {
			define( 'XMLSE_FREE_DIR', $xmlse_resolved );
			break;
		}
	}
}

// Stub WP core classes so `instanceof` checks pass under PHPUnit.
if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core class stub for unit tests.
	#[\AllowDynamicProperties]
	class WP_Post {
		public $ID            = 0;
		public $post_type     = '';
		public $post_status   = '';
		public $post_author   = 0;
		public $post_password = '';
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core class stub for unit tests.
	class WP_Error {
		private $errors = array();
		private $code   = '';
		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ] = $message;
				$this->code            = (string) $code;
			}
		}
		public function get_error_message() {
			return (string) reset( $this->errors );
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}

// WP time constants.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Load free-plugin classes the add-on depends on. Keep this list minimal
// and stable — anything not strictly required by the tests should be
// stubbed inline in the test file instead.
if ( defined( 'XMLSE_FREE_DIR' ) ) {
	require_once XMLSE_FREE_DIR . '/inc/admin/class-sanitize.php';
	require_once XMLSE_FREE_DIR . '/inc/admin/class-sitemap-settings.php';
}

// Source-tree constants required by `XMLSE\Advanced\License` to find the
// vendored EDD updater file. Mirrors what the bootstrap defines at runtime.
if ( ! defined( 'XMLSE_ADV_DIR' ) ) {
	define( 'XMLSE_ADV_DIR', realpath( __DIR__ . '/..' ) . '/' );
}
if ( ! defined( 'XMLSE_ADV_FILE' ) ) {
	define( 'XMLSE_ADV_FILE', XMLSE_ADV_DIR . 'xml-sitemap-engines-advanced.php' );
}
if ( ! defined( 'XMLSE_ADV_VERSION' ) ) {
	define( 'XMLSE_ADV_VERSION', '0.1.0' );
}

// Load add-on classes under test.
require_once __DIR__ . '/../inc/admin/class-gsc-integration.php';
require_once __DIR__ . '/../inc/connectors/class-abstract-connector.php';
require_once __DIR__ . '/../inc/connectors/class-bing.php';
require_once __DIR__ . '/../inc/connectors/class-yandex.php';
require_once __DIR__ . '/../inc/connectors/class-baidu.php';
require_once __DIR__ . '/../inc/class-license.php';
