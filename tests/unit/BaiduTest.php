<?php
/**
 * Unit tests for XMLSE\Advanced\Connectors\Baidu.
 *
 * Baidu's push API uses a long-lived bearer token (no OAuth) — so the
 * config surface is the smallest of the four: just `site` + `token`.
 * Sanitisation for both fields is what we test here.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Advanced\Connectors\Baidu;

/**
 * @covers \XMLSE\Advanced\Connectors\Baidu
 */
final class BaiduTest extends TestCase {

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

	public function test_slug_is_baidu() {
		$this->assertSame( 'baidu', Baidu::slug() );
	}

	public function test_label_is_non_empty() {
		$this->assertNotEmpty( Baidu::label() );
	}

	// --- get_config defaults + auto-fill ---------------------------------

	public function test_get_config_auto_fills_site_from_home_url() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'site'  => '',
				'token' => '',
			)
		);

		$cfg = Baidu::get_config();
		$this->assertSame( 'https://example.test/', $cfg['site'] );
		$this->assertSame( '', $cfg['token'] );
	}

	public function test_get_config_preserves_saved_token() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'site'  => 'https://example.test/',
				'token' => 'abc123def456',
			)
		);

		$cfg = Baidu::get_config();
		$this->assertSame( 'abc123def456', $cfg['token'] );
	}

	public function test_get_config_handles_corrupt_option() {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$cfg = Baidu::get_config();
		$this->assertSame( '', $cfg['token'] );
		$this->assertSame( 'https://example.test/', $cfg['site'] );
	}

	// --- is_configured ----------------------------------------------------

	public function test_is_configured_requires_both_site_and_token() {
		// Token missing → false
		Functions\when( 'get_option' )->justReturn(
			array(
				'site'  => 'https://example.test/',
				'token' => '',
			)
		);
		$this->assertFalse( Baidu::is_configured() );

		// Both present → true
		Functions\when( 'get_option' )->justReturn(
			array(
				'site'  => 'https://example.test/',
				'token' => 'abc123',
			)
		);
		$this->assertTrue( Baidu::is_configured() );
	}

	// --- sanitize_config: token strips non-alphanumeric ------------------

	public function test_sanitize_strips_special_chars_from_token() {
		// Regex `/[^a-zA-Z0-9]/` keeps only alphanumeric in order — tags
		// like `<script>` become `script` after the brackets/slashes go.
		$out = Baidu::sanitize_config(
			array(
				'site'  => 'https://example.test/',
				'token' => "  to<script>k</script>en!@#$%^123  ",
			)
		);
		$this->assertSame( 'toscriptkscripten123', $out['token'] );
	}

	public function test_sanitize_preserves_alphanumeric_token() {
		$out = Baidu::sanitize_config(
			array(
				'site'  => 'https://example.test/',
				'token' => 'abc123XYZ',
			)
		);
		$this->assertSame( 'abc123XYZ', $out['token'] );
	}

	public function test_sanitize_returns_empty_shape_for_non_array() {
		$out = Baidu::sanitize_config( 'rejected' );
		$this->assertSame(
			array(
				'site'  => '',
				'token' => '',
			),
			$out
		);
	}

	// --- sanitize_config: foreign site rejected --------------------------

	public function test_sanitize_rejects_foreign_site() {
		$out = Baidu::sanitize_config(
			array(
				'site'  => 'https://attacker.test/',
				'token' => 'abc123',
			)
		);
		$this->assertSame( '', $out['site'] );
		// Token still preserved.
		$this->assertSame( 'abc123', $out['token'] );
	}

	public function test_sanitize_accepts_same_host_site() {
		$out = Baidu::sanitize_config(
			array(
				'site'  => 'https://example.test/wp/',
				'token' => 'abc123',
			)
		);
		$this->assertSame( 'https://example.test/wp/', $out['site'] );
	}
}
