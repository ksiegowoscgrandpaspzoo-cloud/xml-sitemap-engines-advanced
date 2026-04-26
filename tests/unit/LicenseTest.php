<?php
/**
 * Unit tests for XMLSE\Advanced\License — EDD SL activation flow + grace
 * period gating + status surface.
 *
 * Outgoing HTTP mocked via Brain\Monkey overrides for `wp_remote_post`. The
 * goal is to lock down the truth tables that drive `is_active()` (the gate
 * filter callback for `xmlse_advanced_enabled` / `xmlse_news_advanced_enabled`),
 * and the persistence rules around `activate()` / `deactivate()` / `check()`.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XMLSE\Advanced\License;

/**
 * @covers \XMLSE\Advanced\License
 */
final class LicenseTest extends TestCase {

	/**
	 * In-memory option store. The setUp method aliases `get_option` /
	 * `update_option` / `delete_option` against this so each test starts
	 * from a clean slate without touching a real DB.
	 *
	 * @var array<string, mixed>
	 */
	private $options = array();

	/**
	 * Captures the most recent body sent through `wp_remote_post`.
	 *
	 * @var array|null
	 */
	private $last_post_body = null;

	/**
	 * URL of the most recent `wp_remote_post` call.
	 *
	 * @var string|null
	 */
	private $last_post_url = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options        = array();
		$this->last_post_body = null;
		$this->last_post_url  = null;

		Functions\stubs(
			array(
				'sanitize_text_field' => static function ( $v ) {
					return trim( strip_tags( (string) $v ) );
				},
				'wp_strip_all_tags'   => static function ( $v ) {
					return strip_tags( (string) $v );
				},
				'esc_url_raw'         => static function ( $v ) { return (string) $v; },
				'wp_unslash'          => static function ( $v ) { return $v; },
				'home_url'            => static function ( $path = '' ) {
					return 'https://example.test/' . ltrim( (string) $path, '/' );
				},
				'admin_url'           => static function ( $path = '' ) {
					return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
				},
				'is_wp_error'         => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'__'                  => static function ( $s ) { return $s; },
				'esc_html__'          => static function ( $s ) { return $s; },
			)
		);

		// In-memory option backend.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options )
					? $this->options[ $name ]
					: $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// Default `wp_remote_*` family — succeed with `success=true,license=valid`
		// unless overridden in the individual test.
		$this->stub_http_success(
			array(
				'success'        => true,
				'license'        => License::STATUS_VALID,
				'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
				'customer_email' => 'buyer@example.test',
				'license_limit'  => 1,
				'site_count'     => 1,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Pin `wp_remote_post` to return a 200 with the given JSON body.
	 *
	 * @param array $body Decoded body the test expects.
	 */
	private function stub_http_success( array $body ): void {
		$captured_url  = &$this->last_post_url;
		$captured_body = &$this->last_post_body;

		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args = array() ) use ( &$captured_url, &$captured_body, $body ) {
				$captured_url  = (string) $url;
				$captured_body = isset( $args['body'] ) ? (array) $args['body'] : array();
				return array(
					'_response_code' => 200,
					'_body'          => json_encode( $body ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['_response_code'] )
					? (int) $response['_response_code']
					: 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['_body'] )
					? (string) $response['_body']
					: '';
			}
		);
	}

	/**
	 * Pin `wp_remote_post` to return a `WP_Error` (network failure simulation).
	 */
	private function stub_http_error( string $code = 'http_request_failed' ): void {
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( $code ) {
				return new \WP_Error( $code, 'simulated transport failure' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
	}

	// ---------------------------------------------------------------------
	// is_active() truth table
	// ---------------------------------------------------------------------

	public function test_is_active_returns_false_when_no_key_stored() {
		$this->assertFalse( License::is_active() );
	}

	public function test_is_active_returns_true_when_status_valid_and_fresh_check_and_not_expired() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time() - HOUR_IN_SECONDS,
		);

		$this->assertTrue( License::is_active() );
	}

	public function test_is_active_returns_true_within_grace_period_when_check_stale() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			// 10 days ago — past FRESH_WINDOW (7 days), inside grace (+14 days).
			'last_check'     => time() - 10 * DAY_IN_SECONDS,
		);

		$this->assertTrue( License::is_active() );
	}

	public function test_is_active_returns_false_when_grace_window_lapsed() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			// 30 days ago — past FRESH (7) + GRACE (14) = 21 days.
			'last_check'     => time() - 30 * DAY_IN_SECONDS,
		);

		$this->assertFalse( License::is_active() );
	}

	public function test_is_active_returns_false_when_status_expired() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_EXPIRED,
			'expires'        => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time() - HOUR_IN_SECONDS,
		);

		$this->assertFalse( License::is_active() );
	}

	public function test_is_active_returns_false_when_expiry_passed_even_if_status_valid() {
		// Edge case: status not yet flipped to expired by daily cron, but
		// the date itself is past. Defence in depth.
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time() - HOUR_IN_SECONDS,
		);

		$this->assertFalse( License::is_active() );
	}

	public function test_is_active_treats_lifetime_expiry_as_never_expiring() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => 'lifetime',
			'customer_email' => 'buyer@example.test',
			'last_check'     => time() - HOUR_IN_SECONDS,
		);

		$this->assertTrue( License::is_active() );
	}

	// ---------------------------------------------------------------------
	// activate()
	// ---------------------------------------------------------------------

	public function test_activate_posts_correct_body_and_persists_on_success() {
		Filters\expectApplied( 'xmlse_pro_license_api_url' )->andReturnFirstArg();
		Filters\expectApplied( 'xmlse_pro_license_item_id' )->andReturnFirstArg();

		$response = License::activate( '  KEY-ABC  ' );

		$this->assertNotEmpty( $response );
		$this->assertSame( License::STATUS_VALID, $response['license'] );
		// URL is the placeholder constant — locks the call surface in case
		// future refactors accidentally hit a different endpoint.
		$this->assertSame( License::LICENSE_API_URL, $this->last_post_url );

		$this->assertIsArray( $this->last_post_body );
		$this->assertSame( 'activate_license', $this->last_post_body['edd_action'] );
		$this->assertSame( 'KEY-ABC', $this->last_post_body['license'] );
		$this->assertSame( 'https://example.test/', $this->last_post_body['url'] );

		// Persisted.
		$this->assertArrayHasKey( License::OPTION, $this->options );
		$persisted = $this->options[ License::OPTION ];
		$this->assertSame( 'KEY-ABC', $persisted['key'] );
		$this->assertSame( License::STATUS_VALID, $persisted['status'] );
		$this->assertSame( 'buyer@example.test', $persisted['customer_email'] );
		$this->assertGreaterThan( 0, $persisted['last_check'] );
	}

	public function test_activate_does_not_persist_key_when_edd_returns_success_false() {
		$this->stub_http_success(
			array(
				'success' => false,
				'license' => License::STATUS_INVALID,
			)
		);

		$response = License::activate( 'BAD-KEY' );

		$this->assertSame( License::STATUS_INVALID, $response['license'] );

		// last_check was bumped on the empty record so the UI can show
		// "we tried at HH:MM:SS", but the key MUST NOT be persisted.
		$persisted = $this->options[ License::OPTION ] ?? array();
		$this->assertSame( '', (string) ( $persisted['key'] ?? '' ) );
		$this->assertSame( License::STATUS_INVALID, $persisted['status'] ?? '' );
	}

	public function test_activate_returns_error_for_empty_key() {
		$response = License::activate( '   ' );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'empty_key', $response['error'] );
	}

	public function test_activate_returns_transport_error_on_wp_error() {
		$this->stub_http_error( 'http_request_failed' );

		$response = License::activate( 'KEY-XYZ' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'http_request_failed', $response['error'] );
		// Did not persist the key.
		$persisted = $this->options[ License::OPTION ] ?? array();
		$this->assertEmpty( $persisted['key'] ?? '' );
	}

	// ---------------------------------------------------------------------
	// deactivate()
	// ---------------------------------------------------------------------

	public function test_deactivate_clears_stored_key() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time(),
		);

		$this->stub_http_success(
			array(
				'success' => true,
				'license' => 'deactivated',
			)
		);

		$result = License::deactivate();

		$this->assertTrue( $result );
		$this->assertArrayNotHasKey( License::OPTION, $this->options );
	}

	public function test_deactivate_clears_local_state_even_on_transport_failure() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time(),
		);

		$this->stub_http_error();

		$result = License::deactivate();

		// Returns false (transport failed) but local copy is still wiped —
		// otherwise a dead store would lock the customer out.
		$this->assertFalse( $result );
		$this->assertArrayNotHasKey( License::OPTION, $this->options );
	}

	public function test_deactivate_no_op_when_no_key_stored() {
		$result = License::deactivate();
		$this->assertTrue( $result );
	}

	// ---------------------------------------------------------------------
	// check()
	// ---------------------------------------------------------------------

	public function test_check_updates_last_check_timestamp_and_status() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time() - 10 * DAY_IN_SECONDS,
		);

		$response = License::check();

		$this->assertSame( License::STATUS_VALID, $response['license'] );
		$persisted = $this->options[ License::OPTION ];
		$this->assertGreaterThan( time() - 5, $persisted['last_check'] );
		$this->assertSame( 'KEY-123', $persisted['key'] ); // Key untouched.
	}

	public function test_check_returns_never_activated_when_no_key() {
		$response = License::check();

		$this->assertFalse( $response['success'] );
		$this->assertSame( License::STATUS_NEVER_ACTIVATED, $response['license'] );
	}

	public function test_check_bumps_last_check_on_transport_failure_to_let_grace_lapse() {
		// Stored: last_check 10 days ago, status valid (in grace).
		$ten_days_ago                     = time() - 10 * DAY_IN_SECONDS;
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => $ten_days_ago,
		);

		$this->stub_http_error();

		License::check();

		// last_check was bumped — without this a permanently broken cron
		// would never let the grace window elapse.
		$persisted = $this->options[ License::OPTION ];
		$this->assertGreaterThan( $ten_days_ago, $persisted['last_check'] );
		// Status preserved (we couldn't talk to EDD).
		$this->assertSame( License::STATUS_VALID, $persisted['status'] );
	}

	public function test_check_promotes_expired_response_into_persisted_state() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
			'customer_email' => 'buyer@example.test',
			'last_check'     => time(),
		);
		$this->stub_http_success(
			array(
				'success' => false,
				'license' => License::STATUS_EXPIRED,
				'expires' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
			)
		);

		License::check();

		$persisted = $this->options[ License::OPTION ];
		$this->assertSame( License::STATUS_EXPIRED, $persisted['status'] );
		$this->assertFalse( License::is_active() );
	}

	// ---------------------------------------------------------------------
	// status() shape
	// ---------------------------------------------------------------------

	public function test_status_returns_5_key_shape_when_unset() {
		$state = License::status();

		$this->assertSame(
			array( 'key', 'status', 'expires', 'customer_email', 'last_check' ),
			array_keys( $state )
		);
		$this->assertSame( '', $state['key'] );
		$this->assertSame( License::STATUS_NEVER_ACTIVATED, $state['status'] );
		$this->assertSame( '', $state['expires'] );
		$this->assertSame( '', $state['customer_email'] );
		$this->assertSame( 0, $state['last_check'] );
	}

	public function test_status_normalises_non_array_option_value() {
		$this->options[ License::OPTION ] = 'corrupt-string';

		$state = License::status();

		$this->assertSame( '', $state['key'] );
		$this->assertSame( License::STATUS_NEVER_ACTIVATED, $state['status'] );
	}

	// ---------------------------------------------------------------------
	// extend_status_filter() — backfill for the free side
	// ---------------------------------------------------------------------

	public function test_extend_status_filter_merges_edd_fields_into_free_status() {
		$this->options[ License::OPTION ] = array(
			'key'            => 'KEY-123',
			'status'         => License::STATUS_VALID,
			'expires'        => '2027-04-22',
			'customer_email' => 'buyer@example.test',
			'last_check'     => 1_700_000_000,
		);

		$free_status = array(
			'active'      => true,
			'version'     => '0.1.0',
			'outdated'    => false,
			'min_version' => '0.1.0',
		);

		$result = License::extend_status_filter( $free_status );

		$this->assertSame( '2027-04-22', $result['expires'] );
		$this->assertSame( 'buyer@example.test', $result['customer_email'] );
		$this->assertSame( License::STATUS_VALID, $result['license_status'] );
		$this->assertTrue( $result['active'] );
		$this->assertSame( '0.1.0', $result['version'] );
	}

	public function test_extend_status_filter_handles_non_array_input() {
		// Defensive: a plugin somewhere along the filter chain may have
		// fed `null` / a string. Should not blow up.
		$result = License::extend_status_filter( null );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'license_status', $result );
	}

	// ---------------------------------------------------------------------
	// mask_key()
	// ---------------------------------------------------------------------

	public function test_mask_key_keeps_last_four_characters() {
		$this->assertSame( '************KEYZ', License::mask_key( 'ABCDEFGHIJKLKEYZ' ) );
	}

	public function test_mask_key_handles_short_keys() {
		$this->assertSame( '****', License::mask_key( 'ABCD' ) );
		$this->assertSame( '', License::mask_key( '' ) );
	}

	// ---------------------------------------------------------------------
	// stored_key() — used by the updater bootstrap
	// ---------------------------------------------------------------------

	public function test_stored_key_returns_empty_when_unset() {
		$this->assertSame( '', License::stored_key() );
	}

	public function test_stored_key_returns_persisted_key() {
		$this->options[ License::OPTION ] = array(
			'key'    => 'KEY-XYZ',
			'status' => License::STATUS_VALID,
		);
		$this->assertSame( 'KEY-XYZ', License::stored_key() );
	}

	// ---------------------------------------------------------------------
	// api_url() / item_id() filters
	// ---------------------------------------------------------------------

	public function test_api_url_is_filterable() {
		Filters\expectApplied( 'xmlse_pro_license_api_url' )
			->once()
			->andReturn( 'https://store.example.com/' );

		$this->assertSame( 'https://store.example.com/', License::api_url() );
	}

	public function test_item_id_is_filterable() {
		Filters\expectApplied( 'xmlse_pro_license_item_id' )
			->once()
			->andReturn( 12345 );

		$this->assertSame( 12345, License::item_id() );
	}
}
