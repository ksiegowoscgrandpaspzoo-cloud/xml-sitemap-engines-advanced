<?php
/**
 * Unit tests for XMLSE\Advanced\Connectors\Abstract_Connector — focusing
 * on the SSRF guards `host_belongs_to_this_site()` and
 * `url_belongs_to_this_site()` plus `submit_sitemap()` happy-path.
 *
 * The shared validators are reused by every concrete connector + by
 * the GSC integration helper, so a regression here breaks all four
 * engines simultaneously.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Advanced\Connectors\Abstract_Connector;

/**
 * Concrete subclass for testing the abstract — only `do_submit_sitemap`
 * needs an implementation; everything else is inherited.
 */
final class FakeConnector extends Abstract_Connector {
	public static $last_submit_url = null;
	public static $next_submit_result = true;

	public static function slug() {
		return 'fake';
	}

	public static function label() {
		return 'Fake';
	}

	protected static function do_submit_sitemap( $sitemap_url ) {
		self::$last_submit_url = $sitemap_url;
		return self::$next_submit_result;
	}
}

/**
 * @covers \XMLSE\Advanced\Connectors\Abstract_Connector
 */
final class AbstractConnectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'home_url'        => static function ( $path = '' ) {
					return 'https://example.test/' . ltrim( (string) $path, '/' );
				},
				'wp_parse_url'    => static function ( $url, $component = -1 ) {
					return -1 === $component ? wp_parse_url_native( $url ) : wp_parse_url_native( $url, $component );
				},
				'is_wp_error'     => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'__'              => static function ( $s ) { return $s; },
				'wp_unslash'      => static function ( $v ) { return $v; },
				'sanitize_text_field' => static function ( $v ) { return trim( strip_tags( (string) $v ) ); },
			)
		);

		// Reset fake state between tests.
		FakeConnector::$last_submit_url = null;
		FakeConnector::$next_submit_result = true;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// =====================================================================
	// host_belongs_to_this_site() — used by every "Verified site URL" field
	// =====================================================================

	public function test_host_match_exact() {
		$this->assertTrue( FakeConnector::host_belongs_to_this_site( 'https://example.test/' ) );
	}

	public function test_host_match_is_case_insensitive() {
		$this->assertTrue( FakeConnector::host_belongs_to_this_site( 'https://Example.TEST/foo' ) );
	}

	public function test_host_match_accepts_bare_host() {
		$this->assertTrue( FakeConnector::host_belongs_to_this_site( 'example.test' ) );
	}

	public function test_host_match_strips_pasted_partial_scheme() {
		// User pastes a malformed URL like `https:example.test` — strip and
		// compare. Not a security concern (we wouldn't accept it), but
		// preserving the host comparison is a UX courtesy.
		$this->assertTrue( FakeConnector::host_belongs_to_this_site( 'https:example.test' ) );
	}

	public function test_host_rejects_foreign_domain() {
		$this->assertFalse( FakeConnector::host_belongs_to_this_site( 'https://attacker.example.com/' ) );
	}

	public function test_host_rejects_subdomain_mismatch() {
		// Search engines treat www.example.test and example.test as
		// SEPARATE properties, so we deliberately reject one as the other.
		$this->assertFalse( FakeConnector::host_belongs_to_this_site( 'https://www.example.test/' ) );
	}

	public function test_host_rejects_empty_string() {
		$this->assertFalse( FakeConnector::host_belongs_to_this_site( '' ) );
	}

	public function test_host_rejects_path_trailing_attempt() {
		// Path/query after a foreign host must not trick the matcher.
		$this->assertFalse( FakeConnector::host_belongs_to_this_site( 'https://attacker.test/example.test' ) );
	}

	// =====================================================================
	// url_belongs_to_this_site() — stricter, used by submit handler
	// =====================================================================

	public function test_url_belongs_same_host_root_path() {
		$this->assertTrue( FakeConnector::url_belongs_to_this_site( 'https://example.test/sitemap.xml' ) );
	}

	public function test_url_belongs_rejects_foreign_host() {
		$this->assertFalse( FakeConnector::url_belongs_to_this_site( 'https://attacker.test/sitemap.xml' ) );
	}

	public function test_url_belongs_rejects_empty() {
		$this->assertFalse( FakeConnector::url_belongs_to_this_site( '' ) );
	}

	public function test_url_belongs_rejects_malformed_url() {
		$this->assertFalse( FakeConnector::url_belongs_to_this_site( 'not-a-url' ) );
	}

	public function test_url_belongs_case_insensitive_host() {
		$this->assertTrue( FakeConnector::url_belongs_to_this_site( 'https://EXAMPLE.test/sitemap.xml' ) );
	}

	// =====================================================================
	// submit_sitemap() — empty-URL guard + happy-path
	// =====================================================================

	public function test_submit_sitemap_rejects_empty_url() {
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$result = FakeConnector::submit_sitemap( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( FakeConnector::$last_submit_url, 'do_submit_sitemap must not be called for empty URL' );
	}

	public function test_submit_sitemap_passes_url_through_to_template_method() {
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$result = FakeConnector::submit_sitemap( 'https://example.test/sitemap.xml' );
		$this->assertTrue( $result );
		$this->assertSame( 'https://example.test/sitemap.xml', FakeConnector::$last_submit_url );
	}
}

// ---------------------------------------------------------------------------
// Non-stubbed `parse_url` shim — Brain Monkey's Functions\stubs() takes a
// closure, but PHP's native `parse_url` isn't in the stubs map. We wrap it.
// ---------------------------------------------------------------------------
if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_url_native' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- helper for tests.
	function wp_parse_url_native( $url, $component = -1 ) {
		return -1 === $component ? \parse_url( $url ) : \parse_url( $url, $component );
	}
}
