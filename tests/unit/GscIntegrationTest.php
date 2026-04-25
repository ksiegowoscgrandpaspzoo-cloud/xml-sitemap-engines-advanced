<?php
/**
 * Unit tests for XMLSE\Advanced\Admin\GSC_Integration.
 *
 * Covers pure-function helpers and option round-trips. The OAuth handlers
 * end with `wp_redirect` + `exit`, so they're not tested here — the
 * critical logic lives in `ensure_access_token`, `read_tokens`,
 * `write_tokens`, `record_submission`, and the configuration accessors,
 * all of which are exercised below.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Admin\Sanitize;
use XMLSE\Advanced\Admin\GSC_Integration;

/**
 * @covers \XMLSE\Advanced\Admin\GSC_Integration
 */
final class GscIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'sanitize_text_field' => static function ( $v ) {
					return trim( strip_tags( (string) $v ) );
				},
				'wp_strip_all_tags'   => static function ( $v ) {
					return strip_tags( (string) $v );
				},
				'esc_url_raw'         => static function ( $v ) {
					return (string) $v;
				},
				'wp_json_encode'      => static function ( $v ) {
					return json_encode( $v );
				},
				'admin_url'           => static function ( $path = '' ) {
					return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
				},
				// Sprint 1 moved sanitize_config() onto GSC_Integration which
				// host-validates `site_url` against home_url() — these stubs
				// support that path. Without them every sanitize-test would
				// die in `Abstract_Connector::host_belongs_to_this_site()`.
				'home_url'            => static function ( $path = '' ) {
					return 'https://example.test/' . ltrim( (string) $path, '/' );
				},
				'trailingslashit'     => static function ( $s ) {
					return rtrim( (string) $s, '/' ) . '/';
				},
				'wp_parse_url'        => static function ( $url, $component = -1 ) {
					return -1 === $component ? \parse_url( $url ) : \parse_url( $url, $component );
				},
				'__'                  => static function ( $s ) { return $s; },
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- is_enabled -------------------------------------------------------

	public function test_is_enabled_reflects_advanced_filter() {
		Filters\expectApplied( 'xmlse_advanced_enabled' )->andReturn( true );
		$this->assertTrue( GSC_Integration::is_enabled() );
	}

	public function test_is_enabled_defaults_false() {
		Filters\expectApplied( 'xmlse_advanced_enabled' )->andReturn( false );
		$this->assertFalse( GSC_Integration::is_enabled() );
	}

	// --- redirect_uri -----------------------------------------------------

	public function test_redirect_uri_uses_admin_post_action() {
		$uri = GSC_Integration::redirect_uri();
		$this->assertSame(
			'https://example.test/wp-admin/admin-post.php?action=xmlse_gsc_oauth_callback',
			$uri
		);
	}

	// --- get_config + is_configured --------------------------------------

	public function test_get_config_returns_empty_defaults() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
			)
		);
		$cfg = GSC_Integration::get_config();
		$this->assertSame( '', $cfg['client_id'] );
		$this->assertSame( '', $cfg['client_secret'] );
		// Sprint 2 UX iteration: empty site_url auto-fills from home_url()
		// so the readonly form field always shows the current property URL.
		$this->assertSame( 'https://example.test/', $cfg['site_url'] );
	}

	public function test_is_configured_requires_all_three_fields() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => 'abc',
				'client_secret' => '',
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertFalse( GSC_Integration::is_configured() );

		Functions\when( 'get_option' )->justReturn(
			array(
				'client_id'     => 'abc',
				'client_secret' => 'secret',
				'site_url'      => 'https://example.test/',
			)
		);
		$this->assertTrue( GSC_Integration::is_configured() );
	}

	public function test_get_config_survives_non_array_option_value() {
		Functions\when( 'get_option' )->justReturn( false );
		$cfg = GSC_Integration::get_config();
		$this->assertSame( '', $cfg['client_id'] );
		$this->assertSame( '', $cfg['client_secret'] );
		// Sprint 2: empty site_url is auto-filled from home_url() so the
		// readonly form field always shows the current property URL.
		$this->assertSame( 'https://example.test/', $cfg['site_url'] );
	}

	// --- tokens round-trip -----------------------------------------------

	public function test_tokens_round_trip_through_option_store() {
		$captured = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$captured ) {
				return GSC_Integration::TOKENS_OPTION === $name ? (string) $captured : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				if ( GSC_Integration::TOKENS_OPTION === $name ) {
					$captured = $value;
				}
			}
		);
		Filters\expectApplied( 'xmlse_gsc_tokens_filter_write' )->andReturnFirstArg();
		Filters\expectApplied( 'xmlse_gsc_tokens_filter_read' )->andReturnFirstArg();

		GSC_Integration::write_tokens(
			array(
				'access_token'  => 'ya29.fake-access',
				'refresh_token' => '1//fake-refresh',
				'expires_at'    => 1_700_000_000,
				'token_type'    => 'Bearer',
			)
		);

		$this->assertIsString( $captured );
		$this->assertNotEmpty( $captured );
		// The raw payload should be base64-encoded JSON.
		$this->assertNotFalse( base64_decode( $captured, true ) );

		$read = GSC_Integration::read_tokens();
		$this->assertSame( 'ya29.fake-access', $read['access_token'] );
		$this->assertSame( '1//fake-refresh', $read['refresh_token'] );
		$this->assertSame( 1_700_000_000, $read['expires_at'] );
	}

	public function test_read_tokens_returns_empty_skeleton_when_unset() {
		Functions\when( 'get_option' )->justReturn( '' );
		Filters\expectApplied( 'xmlse_gsc_tokens_filter_read' )->andReturnFirstArg();

		$read = GSC_Integration::read_tokens();

		$this->assertSame( '', $read['access_token'] );
		$this->assertSame( '', $read['refresh_token'] );
		$this->assertSame( 0, $read['expires_at'] );
	}

	public function test_is_connected_requires_refresh_token() {
		Functions\when( 'get_option' )->justReturn( '' );
		Filters\expectApplied( 'xmlse_gsc_tokens_filter_read' )->andReturnFirstArg();
		$this->assertFalse( GSC_Integration::is_connected() );
	}

	// --- log storage ------------------------------------------------------

	public function test_record_submission_prepends_and_trims_to_cap() {
		$existing = array();
		for ( $i = 0; $i < GSC_Integration::LOG_MAX; $i++ ) {
			$existing[] = array(
				'url'     => 'https://example.test/old-' . $i . '.xml',
				'time'    => 0,
				'status'  => 200,
				'message' => 'OK',
			);
		}

		$stored = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $existing ) {
				return GSC_Integration::LOG_OPTION === $name ? $existing : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				if ( GSC_Integration::LOG_OPTION === $name ) {
					$stored = $value;
				}
			}
		);

		GSC_Integration::record_submission( 'https://example.test/new.xml', 200, 'OK' );

		$this->assertCount( GSC_Integration::LOG_MAX, $stored );
		$this->assertSame( 'https://example.test/new.xml', $stored[0]['url'] );
		$this->assertSame( 200, $stored[0]['status'] );
	}

	// --- Sanitize::gsc_config --------------------------------------------

	public function test_sanitize_rejects_non_array_input() {
		$out = GSC_Integration::sanitize_config( 'garbage' );
		$this->assertSame( '', $out['client_id'] );
		$this->assertSame( '', $out['client_secret'] );
		$this->assertSame( '', $out['site_url'] );
	}

	public function test_sanitize_trims_client_id() {
		$out = GSC_Integration::sanitize_config( array( 'client_id' => '  abc123.apps.googleusercontent.com  ' ) );
		$this->assertSame( 'abc123.apps.googleusercontent.com', $out['client_id'] );
	}

	public function test_sanitize_preserves_site_url_prefix_property() {
		// Sprint 1 added host validation — site_url must match the current
		// site (home_url stub: https://example.test/).
		$out = GSC_Integration::sanitize_config( array( 'site_url' => 'https://example.test/' ) );
		$this->assertSame( 'https://example.test/', $out['site_url'] );
	}

	public function test_sanitize_preserves_domain_property_prefix() {
		// Domain-property format `sc-domain:HOST` — host portion is still
		// validated against home_url(), but the `sc-domain:` prefix is
		// preserved verbatim because Search Console treats it as a separate
		// property type from URL-prefix.
		$out = GSC_Integration::sanitize_config( array( 'site_url' => 'sc-domain:example.test' ) );
		$this->assertSame( 'sc-domain:example.test', $out['site_url'] );
	}

	public function test_sanitize_rejects_foreign_site_url_prefix() {
		$out = GSC_Integration::sanitize_config( array( 'site_url' => 'https://attacker.test/' ) );
		$this->assertSame( '', $out['site_url'] );
	}

	public function test_sanitize_rejects_foreign_sc_domain() {
		$out = GSC_Integration::sanitize_config( array( 'site_url' => 'sc-domain:attacker.test' ) );
		$this->assertSame( '', $out['site_url'] );
	}

	public function test_sanitize_strips_tags_from_client_secret() {
		$out = GSC_Integration::sanitize_config(
			array( 'client_secret' => '  <script>evil</script>GOCSPX-realSecret  ' )
		);
		$this->assertSame( 'evilGOCSPX-realSecret', $out['client_secret'] );
	}

	// --- Phase 35.2 — auto-submit rate guard -----------------------------

	public function test_rate_budget_allows_first_ever_submit() {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertTrue( GSC_Integration::rate_budget_allows_submit( 'https://example.test/sitemap-news.xml' ) );
	}

	public function test_rate_budget_blocks_within_24_hours() {
		$url = 'https://example.test/sitemap-news.xml';
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $url ) {
				if ( GSC_Integration::AUTO_SUBMIT_AT_OPTION === $name ) {
					return array( $url => time() - 60 ); // a minute ago
				}
				return $default;
			}
		);

		$this->assertFalse( GSC_Integration::rate_budget_allows_submit( $url ) );
	}

	public function test_rate_budget_permits_after_24_hours() {
		$url = 'https://example.test/sitemap-news.xml';
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $url ) {
				if ( GSC_Integration::AUTO_SUBMIT_AT_OPTION === $name ) {
					return array( $url => time() - 2 * GSC_Integration::AUTO_SUBMIT_RATE_WINDOW );
				}
				return $default;
			}
		);

		$this->assertTrue( GSC_Integration::rate_budget_allows_submit( $url ) );
	}

	public function test_mark_auto_submitted_stores_timestamp() {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				if ( GSC_Integration::AUTO_SUBMIT_AT_OPTION === $name ) {
					$stored = $value;
				}
			}
		);

		GSC_Integration::mark_auto_submitted( 'https://example.test/sitemap-news.xml' );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'https://example.test/sitemap-news.xml', $stored );
		$this->assertGreaterThan( 0, $stored['https://example.test/sitemap-news.xml'] );
	}

	public function test_auto_submit_short_circuits_when_premium_off() {
		$fired = false;
		Filters\expectApplied( 'xmlse_advanced_enabled' )->andReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$post = new \WP_Post();
		$post->ID          = 1;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		GSC_Integration::maybe_auto_submit_on_publish( 'publish', 'draft', $post );

		$this->assertFalse( $fired );
	}

	public function test_auto_submit_short_circuits_when_blog_private() {
		$fired = false;
		Filters\expectApplied( 'xmlse_advanced_enabled' )->andReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return 'blog_public' === $name ? 0 : $default;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$post = new \WP_Post();
		$post->ID          = 1;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		GSC_Integration::maybe_auto_submit_on_publish( 'publish', 'draft', $post );

		$this->assertFalse( $fired );
	}

	// --- Sprint 0.1 SEC audit 2 — url_belongs_to_this_site ---------------

	public function test_url_belongs_rejects_foreign_host() {
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$this->assertFalse(
			GSC_Integration::url_belongs_to_this_site( 'https://evil.com/sitemap-news.xml' )
		);
	}

	public function test_url_belongs_accepts_same_host() {
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$this->assertTrue(
			GSC_Integration::url_belongs_to_this_site( 'https://example.test/sitemap-news.xml' )
		);
		$this->assertTrue(
			GSC_Integration::url_belongs_to_this_site( 'https://example.test/wp-sitemap.xml' )
		);
	}

	public function test_url_belongs_respects_subdirectory_install() {
		// home_url is /wp/; a root-level URL must be rejected.
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$this->assertTrue(
			GSC_Integration::url_belongs_to_this_site( 'https://example.test/wp/sitemap-news.xml' )
		);
		$this->assertFalse(
			GSC_Integration::url_belongs_to_this_site( 'https://example.test/sitemap-news.xml' )
		);
	}

	public function test_url_belongs_rejects_empty_url() {
		$this->assertFalse( GSC_Integration::url_belongs_to_this_site( '' ) );
	}

	public function test_url_belongs_is_case_insensitive_on_host() {
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://Example.Test' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( $url );
			}
		);

		$this->assertTrue(
			GSC_Integration::url_belongs_to_this_site( 'https://example.test/sitemap-news.xml' )
		);
	}

	// --- Phase 35.3 — get_sitemap_status ----------------------------------

	public function test_get_sitemap_status_returns_cached_value_when_available() {
		$fake = array(
			'path'          => 'https://example.test/sitemap-news.xml',
			'lastSubmitted' => '2026-05-01T10:00:00Z',
			'errors'        => 0,
		);
		Functions\when( 'get_transient' )->justReturn( $fake );

		// Because the cached hit returns first, wp_remote_get must never fire.
		Functions\when( 'wp_remote_get' )->alias(
			static function () {
				throw new \LogicException( 'Cache hit must not fall through to HTTP' );
			}
		);

		$result = GSC_Integration::get_sitemap_status( 'https://example.test/sitemap-news.xml' );
		$this->assertIsArray( $result );
		$this->assertSame( '2026-05-01T10:00:00Z', $result['lastSubmitted'] );
	}

	public function test_get_sitemap_status_surfaces_404_as_wp_error() {
		Functions\when( 'get_transient' )->justReturn( false );
		// ensure_access_token returns a valid string if tokens exist.
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( GSC_Integration::CONFIG_OPTION === $name ) {
					return array(
						'client_id'     => 'cid',
						'client_secret' => 'sec',
						'site_url'      => 'https://example.test/',
					);
				}
				if ( GSC_Integration::TOKENS_OPTION === $name ) {
					return base64_encode( wp_json_encode( array(
						'access_token'  => 'fresh',
						'refresh_token' => 'r',
						'expires_at'    => time() + 3600,
						'token_type'    => 'Bearer',
					) ) );
				}
				return $default;
			}
		);
		Filters\expectApplied( 'xmlse_gsc_tokens_filter_read' )->andReturnFirstArg();
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":{"code":404,"message":"Not found"}}' );

		$result = GSC_Integration::get_sitemap_status( 'https://example.test/sitemap-news.xml' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_auto_submit_short_circuits_on_publish_to_publish() {
		$fired = false;
		Functions\when( 'wp_remote_request' )->alias(
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$post = new \WP_Post();
		$post->ID          = 1;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		GSC_Integration::maybe_auto_submit_on_publish( 'publish', 'publish', $post );

		$this->assertFalse( $fired );
	}
}
