<?php
/**
 * Unit tests for XMLSE\Advanced\Connectors\Bing.
 *
 * The Bing connector defers actual submission to free-tier IndexNow,
 * so the meaningful logic to test is the configuration surface:
 * `get_config()` defaults + auto-fill, `sanitize_config()` strips +
 * host-validates, `is_configured()` truth table.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Advanced\Connectors\Bing;

/**
 * @covers \XMLSE\Advanced\Connectors\Bing
 */
final class BingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'home_url'        => static function ( $path = '' ) {
					return 'https://example.test/' . ltrim( (string) $path, '/' );
				},
				'trailingslashit' => static function ( $s ) { return rtrim( (string) $s, '/' ) . '/'; },
				'wp_parse_url'    => static function ( $url, $component = -1 ) {
					return -1 === $component ? \parse_url( $url ) : \parse_url( $url, $component );
				},
				'esc_url_raw'     => static function ( $v ) { return (string) $v; },
				'__'              => static function ( $s ) { return $s; },
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- slug + label -----------------------------------------------------

	public function test_slug_is_bing() {
		$this->assertSame( 'bing', Bing::slug() );
	}

	public function test_label_is_non_empty() {
		$this->assertNotEmpty( Bing::label() );
	}

	// --- get_config defaults + auto-fill ---------------------------------

	public function test_get_config_returns_defaults_on_empty_option() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'api_key'  => '',
				'site_url' => '',
			)
		);

		$cfg = Bing::get_config();
		$this->assertSame( '', $cfg['api_key'] );
		// Empty site_url is auto-filled from home_url() with trailing slash.
		$this->assertSame( 'https://example.test/', $cfg['site_url'] );
	}

	public function test_get_config_preserves_saved_values() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'api_key'  => 'abc123XYZ',
				'site_url' => 'https://example.test/wp/',
			)
		);

		$cfg = Bing::get_config();
		$this->assertSame( 'abc123XYZ', $cfg['api_key'] );
		$this->assertSame( 'https://example.test/wp/', $cfg['site_url'] );
	}

	public function test_get_config_handles_non_array_option() {
		Functions\when( 'get_option' )->justReturn( 'corrupted-string-value' );

		$cfg = Bing::get_config();
		$this->assertSame( '', $cfg['api_key'] );
		// Falls back to home_url() trailing-slashed.
		$this->assertSame( 'https://example.test/', $cfg['site_url'] );
	}

	// --- is_configured ----------------------------------------------------

	public function test_is_configured_requires_both_fields() {
		// Both empty initially — but get_config auto-fills site_url, so
		// only api_key starts truly empty. is_configured needs api_key set.
		Functions\when( 'get_option' )->justReturn(
			array(
				'api_key'  => '',
				'site_url' => 'https://example.test/',
			)
		);
		$this->assertFalse( Bing::is_configured() );

		Functions\when( 'get_option' )->justReturn(
			array(
				'api_key'  => 'abc123',
				'site_url' => 'https://example.test/',
			)
		);
		$this->assertTrue( Bing::is_configured() );
	}

	// --- sanitize_config: api_key alphanumeric only ----------------------

	public function test_sanitize_strips_special_chars_from_api_key() {
		// Regex `/[^a-zA-Z0-9]/` strips every non-alphanumeric character —
		// punctuation, whitespace, angle brackets, slashes — keeping only
		// the letters and digits in their original order. So tags like
		// `<script>` become `script` (the brackets and slashes go).
		$out = Bing::sanitize_config(
			array(
				'api_key'  => "  ke<script>y</script>!@#$%^&*()1\n",
				'site_url' => 'https://example.test/',
			)
		);
		$this->assertSame( 'kescriptyscript1', $out['api_key'] );
	}

	public function test_sanitize_preserves_clean_alphanumeric_api_key() {
		$out = Bing::sanitize_config(
			array(
				'api_key'  => 'abc123XYZ',
				'site_url' => 'https://example.test/',
			)
		);
		$this->assertSame( 'abc123XYZ', $out['api_key'] );
	}

	public function test_sanitize_returns_empty_shape_for_non_array_input() {
		$out = Bing::sanitize_config( 'string-input-rejected' );
		$this->assertSame(
			array(
				'api_key'  => '',
				'site_url' => '',
			),
			$out
		);
	}

	// --- sanitize_config: foreign site_url rejected ----------------------

	public function test_sanitize_rejects_foreign_site_url() {
		$out = Bing::sanitize_config(
			array(
				'api_key'  => 'abc123',
				'site_url' => 'https://attacker.test/',
			)
		);
		$this->assertSame( '', $out['site_url'], 'Foreign host must be cleared' );
		// api_key still preserved — only the URL was rejected.
		$this->assertSame( 'abc123', $out['api_key'] );
	}

	public function test_sanitize_accepts_same_host_site_url() {
		$out = Bing::sanitize_config(
			array(
				'api_key'  => 'abc123',
				'site_url' => 'https://example.test/wp/',
			)
		);
		$this->assertSame( 'https://example.test/wp/', $out['site_url'] );
	}
}
