<?php
/**
 * Unit tests for XMLSE\Advanced\Connectors\Yandex.
 *
 * Focus: configuration sanitisation (host validation, secret/id
 * trimming) and the truth table of `is_configured` / `is_connected`.
 * The full OAuth dance (authorize → exchange → refresh) is integration-
 * level and not exercised here — Yandex's endpoints can't be mocked
 * without an HTTP fake we'd build out specifically.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Advanced\Connectors\Yandex;

/**
 * @covers \XMLSE\Advanced\Connectors\Yandex
 */
final class YandexTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'home_url'            => static function ( $path = '' ) {
					return 'https://example.test/' . ltrim( (string) $path, '/' );
				},
				'trailingslashit'     => static function ( $s ) { return rtrim( (string) $s, '/' ) . '/'; },
				'wp_parse_url'        => static function ( $url, $component = -1 ) {
					return -1 === $component ? \parse_url( $url ) : \parse_url( $url, $component );
				},
				'esc_url_raw'         => static function ( $v ) { return (string) $v; },
				'sanitize_text_field' => static function ( $v ) { return trim( strip_tags( (string) $v ) ); },
				'wp_strip_all_tags'   => static function ( $v ) { return strip_tags( (string) $v ); },
				'__'                  => static function ( $s ) { return $s; },
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_slug_is_yandex() {
		$this->assertSame( 'yandex', Yandex::slug() );
	}

	public function test_label_is_non_empty() {
		$this->assertNotEmpty( Yandex::label() );
	}

	// --- get_config -------------------------------------------------------

	public function test_get_config_returns_full_default_shape() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			)
		);

		$cfg = Yandex::get_config();
		$this->assertArrayHasKey( 'client_id', $cfg );
		$this->assertArrayHasKey( 'client_secret', $cfg );
		$this->assertArrayHasKey( 'site_url', $cfg );
		$this->assertArrayHasKey( 'user_id', $cfg );
		$this->assertArrayHasKey( 'host_id', $cfg );
	}

	public function test_get_config_auto_fills_site_url_from_home_url() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
			)
		);

		$cfg = Yandex::get_config();
		$this->assertSame( 'https://example.test/', $cfg['site_url'] );
	}

	// --- is_configured truth table ---------------------------------------

	public function test_is_configured_requires_all_three_credentials() {
		// Missing client_id → false
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => 'sec',
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertFalse( Yandex::is_configured() );

		// Missing client_secret → false
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => 'cid',
				'client_secret' => '',
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertFalse( Yandex::is_configured() );

		// All three present → true
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'sec',
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertTrue( Yandex::is_configured() );
	}

	// --- sanitize_config: trims + tag-strips text fields -----------------

	public function test_sanitize_trims_client_id_and_strips_tags() {
		// Mock the existing config getter so the user_id / host_id merge
		// has something to work with.
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			)
		);

		$out = Yandex::sanitize_config(
			array(
				'client_id'     => "  abc<script>x</script>123  ",
				'client_secret' => "  s<a>e</a>cret \n",
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertSame( 'abcx123', $out['client_id'] );
		$this->assertSame( 'secret', $out['client_secret'] );
	}

	public function test_sanitize_rejects_foreign_site_url() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			)
		);

		$out = Yandex::sanitize_config(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'sec',
				'site_url'      => 'https://attacker.test/',
			)
		);
		$this->assertSame( '', $out['site_url'] );
		// Credentials still preserved.
		$this->assertSame( 'cid', $out['client_id'] );
	}

	public function test_sanitize_accepts_same_host_url() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			)
		);

		$out = Yandex::sanitize_config(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'sec',
				'site_url'      => 'https://example.test/wp/',
			)
		);
		$this->assertSame( 'https://example.test/wp/', $out['site_url'] );
	}

	public function test_sanitize_returns_empty_shape_for_non_array() {
		$out = Yandex::sanitize_config( 'string-input' );
		$this->assertSame(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			),
			$out
		);
	}

	public function test_sanitize_preserves_existing_user_id_and_host_id() {
		// user_id + host_id come from discovery round-trips, not the form;
		// sanitize must not let a malicious POST overwrite them.
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => 'old',
				'client_secret' => 'old',
				'site_url'      => 'https://example.test/',
				'user_id'       => 12345,
				'host_id'       => 'host-abc-123',
			)
		);

		$out = Yandex::sanitize_config(
			array(
				'client_id'     => 'new',
				'client_secret' => 'new',
				'site_url'      => 'https://example.test/',
				// Attacker tries to inject these.
				'user_id'       => 99999,
				'host_id'       => 'attacker-host',
			)
		);

		$this->assertSame( 12345, $out['user_id'], 'user_id must come from existing config, not POST' );
		$this->assertSame( 'host-abc-123', $out['host_id'], 'host_id must come from existing config, not POST' );
	}
}
