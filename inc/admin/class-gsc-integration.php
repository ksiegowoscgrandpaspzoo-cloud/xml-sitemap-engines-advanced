<?php
/**
 * Google Search Console integration — BYO OAuth + sitemap submit API.
 *
 * Phase 35 feature, gated behind `xmlse_advanced_enabled` (premium). The
 * free tier renders the UI with a premium-lock banner; installs that flip
 * the filter (e.g. via the News Advanced add-on) unlock the full flow:
 *
 *   1. Configure (Client ID + Client Secret + Site URL of the GSC property).
 *   2. Connect (OAuth authorization code dance via admin-post.php redirect URL).
 *   3. Submit (PUT on the Sitemaps resource of the Search Console API).
 *
 * Endpoints (verified May 2026):
 *   - Authorize: https://accounts.google.com/o/oauth2/v2/auth
 *   - Token:     https://oauth2.googleapis.com/token
 *   - Revoke:    https://oauth2.googleapis.com/revoke
 *   - Submit:    PUT https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/sitemaps/{feedpath}
 *
 * OAuth scope: `https://www.googleapis.com/auth/webmasters`.
 *
 * Tokens are stored base64-encoded in an autoload=no option. No encryption
 * layer — standard for WP OAuth integrations (the attack vector is DB read
 * access, which is already game-over). If a host wants real encryption, the
 * `xmlse_gsc_tokens_filter_read` / `xmlse_gsc_tokens_filter_write` filters
 * let them wrap read/write with their own cipher.
 *
 * @package XMLSE
 */

namespace XMLSE\Advanced\Admin;

use XMLSE\Admin\Sitemap_Settings;

defined( 'WPINC' ) || die;

/**
 * Search Console integration controller.
 *
 * @since 0.1.0
 */
final class GSC_Integration {

	/**
	 * Option that stores the public half of the OAuth config — safe to
	 * autoload since `client_secret` + access tokens live in a separate
	 * non-autoloaded option.
	 */
	const CONFIG_OPTION = 'xmlse_gsc_config';

	/**
	 * Option that stores access + refresh tokens. NOT autoloaded — keeps
	 * the alloptions cache small and avoids leaking tokens to every page
	 * load.
	 */
	const TOKENS_OPTION = 'xmlse_gsc_tokens';

	/**
	 * Option that stores the last N submission attempts for display in
	 * the admin log. NOT autoloaded.
	 */
	const LOG_OPTION = 'xmlse_gsc_submission_log';

	/**
	 * Transient name — holds the pending OAuth state (CSRF token) between
	 * the redirect to Google and the callback.
	 */
	const STATE_TRANSIENT = 'xmlse_gsc_oauth_state';

	/**
	 * Log buffer size.
	 */
	const LOG_MAX = 20;

	/**
	 * OAuth scope — read + write access to Search Console properties the
	 * authorising user already has permission for.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters';

	/**
	 * Per-URL rate window for the auto-submit flow (Phase 35.2). Google's
	 * Submit endpoint is idempotent and polling schedule isn't accelerated
	 * by repeat submissions of the same URL, so a 24h ceiling is generous.
	 */
	const AUTO_SUBMIT_RATE_WINDOW = 86400;

	/**
	 * Option that records the last successful auto-submit per URL.
	 * Not autoloaded. Shape: `array<string, int>` (URL => unix timestamp).
	 */
	const AUTO_SUBMIT_AT_OPTION = 'xmlse_gsc_auto_submit_at';

	/**
	 * Register admin-post handlers + settings-field hooks.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_action( 'admin_post_xmlse_gsc_oauth_start', array( __CLASS__, 'handle_oauth_start' ) );
		add_action( 'admin_post_xmlse_gsc_oauth_callback', array( __CLASS__, 'handle_oauth_callback' ) );
		add_action( 'admin_post_xmlse_gsc_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_xmlse_gsc_submit', array( __CLASS__, 'handle_submit' ) );

		// Phase 35.2 — auto-submit the news sitemap URL when a news-eligible
		// post transitions into publish. Rate-guarded to once per URL per day.
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_auto_submit_on_publish' ), 25, 3 );
	}

	// ------------------------------------------------------------------
	// Public accessors
	// ------------------------------------------------------------------

	/**
	 * Whether the premium gate is currently open.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/** This filter is documented in inc/admin/class-premium-lock.php */
		return (bool) apply_filters( 'xmlse_advanced_enabled', false );
	}

	/**
	 * Sanitize the `xmlse_gsc_config` option — used as the Settings
	 * API sanitize_callback. Lived on `XMLSE\Admin\Sanitize::gsc_config`
	 * in the free plugin before Sprint 1; now co-located with the class
	 * that consumes the option.
	 *
	 * Accepted shape:
	 *     array(
	 *         'client_id'     => string,
	 *         'client_secret' => string,
	 *         'site_url'      => string, // URL-prefix or sc-domain:…
	 *     )
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw value.
	 * @return array<string, string>
	 */
	public static function sanitize_config( $input ) {
		$out = array(
			'client_id'     => '',
			'client_secret' => '',
			'site_url'      => '',
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}

		if ( isset( $input['client_id'] ) ) {
			$out['client_id'] = trim( sanitize_text_field( (string) $input['client_id'] ) );
		}
		if ( isset( $input['client_secret'] ) ) {
			// Don't run sanitize_text_field — it strips whitespace-like
			// code points a secret might legally contain. Trim + strip tags.
			$out['client_secret'] = trim( wp_strip_all_tags( (string) $input['client_secret'] ) );
		}
		if ( isset( $input['site_url'] ) ) {
			$raw = trim( (string) $input['site_url'] );
			// Accept both property formats: URL-prefix + domain.
			if ( 0 === strpos( $raw, 'sc-domain:' ) ) {
				$domain = sanitize_text_field( substr( $raw, 10 ) );
				// Reject foreign domains — prevents admin misconfiguration
				// pointing at a property they don't own.
				if ( '' !== $domain && ! \XMLSE\Advanced\Connectors\Abstract_Connector::host_belongs_to_this_site( $domain ) ) {
					$domain = '';
				}
				$out['site_url'] = '' !== $domain ? 'sc-domain:' . $domain : '';
			} else {
				$candidate = esc_url_raw( $raw );
				if ( '' !== $candidate && ! \XMLSE\Advanced\Connectors\Abstract_Connector::host_belongs_to_this_site( $candidate ) ) {
					$candidate = '';
				}
				$out['site_url'] = $candidate;
			}
		}

		return $out;
	}

	/**
	 * Current OAuth config (client id + client secret + site URL).
	 *
	 * @since 0.1.0
	 *
	 * @return array{client_id:string, client_secret:string, site_url:string}
	 */
	public static function get_config() {
		$raw = get_option(
			self::CONFIG_OPTION,
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
			)
		);
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'client_id'     => isset( $raw['client_id'] ) ? (string) $raw['client_id'] : '',
			'client_secret' => isset( $raw['client_secret'] ) ? (string) $raw['client_secret'] : '',
			'site_url'      => isset( $raw['site_url'] ) ? (string) $raw['site_url'] : '',
		);
	}

	/**
	 * Whether the user has configured Client ID + Client Secret + Site URL.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['client_id']
			&& '' !== $cfg['client_secret']
			&& '' !== $cfg['site_url'];
	}

	/**
	 * Whether we currently hold a valid refresh token.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$tokens = self::read_tokens();
		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * The exact redirect URI that must be registered in the user's GCP
	 * project. Built from `admin-post.php` so it's stable across the site.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=xmlse_gsc_oauth_callback' );
	}

	// ------------------------------------------------------------------
	// OAuth flow
	// ------------------------------------------------------------------

	/**
	 * Kick off the OAuth dance — generate state, redirect to Google.
	 *
	 * @since 0.1.0
	 */
	public static function handle_oauth_start() {
		self::require_cap();
		check_admin_referer( 'xmlse_gsc_oauth_start' );

		if ( ! self::is_enabled() ) {
			self::redirect_back( 'gsc_premium_required' );
		}
		if ( ! self::is_configured() ) {
			self::redirect_back( 'gsc_not_configured' );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$cfg           = self::get_config();
		$authorize_url = add_query_arg(
			array(
				'client_id'     => $cfg['client_id'],
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);

		wp_redirect( $authorize_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth redirect to Google.
		exit;
	}

	/**
	 * Complete the dance — Google has redirected the user back with ?code=
	 * or ?error=. Exchange the code for tokens and store them.
	 *
	 * @since 0.1.0
	 */
	public static function handle_oauth_callback() {
		self::require_cap();

		if ( ! self::is_enabled() ) {
			self::redirect_back( 'gsc_premium_required' );
		}

		// OAuth2 callbacks use the `state` parameter for CSRF protection
		// instead of a WordPress nonce — nonces rotate per session, but the
		// redirect URI registered in GCP must be static. The `state` check
		// below (matching the value we set in the transient before the
		// redirect to Google) is the equivalent defence.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			self::redirect_back( 'gsc_oauth_denied' );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$expected = (string) get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );

		if ( '' === $code || '' === $state || $state !== $expected ) {
			self::redirect_back( 'gsc_oauth_state_mismatch' );
		}

		$cfg = self::get_config();

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 10,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::redirect_back( 'gsc_oauth_exchange_failed' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			self::redirect_back( 'gsc_oauth_exchange_failed' );
		}

		self::write_tokens(
			array(
				'access_token'  => (string) $body['access_token'],
				'refresh_token' => isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : self::read_tokens()['refresh_token'] ?? '',
				'expires_at'    => time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ),
				'token_type'    => isset( $body['token_type'] ) ? (string) $body['token_type'] : 'Bearer',
			)
		);

		self::redirect_back( 'gsc_connected' );
	}

	/**
	 * Revoke the stored refresh token + wipe local copy.
	 *
	 * @since 0.1.0
	 */
	public static function handle_disconnect() {
		self::require_cap();
		check_admin_referer( 'xmlse_gsc_disconnect' );

		$tokens  = self::read_tokens();
		$refresh = isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '';
		if ( '' !== $refresh ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout'  => 5,
					'blocking' => false,
					'body'     => array( 'token' => $refresh ),
				)
			);
		}

		delete_option( self::TOKENS_OPTION );
		self::redirect_back( 'gsc_disconnected' );
	}

	/**
	 * Ensure a non-expired access token is on hand, refreshing if needed.
	 *
	 * @since 0.1.0
	 *
	 * @return string|\WP_Error Access token, or WP_Error when refresh fails.
	 */
	public static function ensure_access_token() {
		$tokens = self::read_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'gsc_not_connected', __( 'Google Search Console is not connected.', 'xml-sitemap-engines' ) );
		}

		$expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;
		if ( ! empty( $tokens['access_token'] ) && $expires_at > time() + 60 ) {
			return (string) $tokens['access_token'];
		}

		$cfg      = self::get_config();
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 10,
				'body'    => array(
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
					'refresh_token' => (string) $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'gsc_refresh_failed', (string) wp_remote_retrieve_body( $response ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new \WP_Error( 'gsc_refresh_failed', __( 'Refresh response missing access_token.', 'xml-sitemap-engines' ) );
		}

		self::write_tokens(
			array(
				'access_token'  => (string) $body['access_token'],
				'refresh_token' => (string) $tokens['refresh_token'],
				'expires_at'    => time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ),
				'token_type'    => isset( $body['token_type'] ) ? (string) $body['token_type'] : 'Bearer',
			)
		);

		return (string) $body['access_token'];
	}

	// ------------------------------------------------------------------
	// Submit API
	// ------------------------------------------------------------------

	/**
	 * Admin-post handler for the per-sitemap Submit button.
	 *
	 * @since 0.1.0
	 */
	public static function handle_submit() {
		self::require_cap();
		check_admin_referer( 'xmlse_gsc_submit' );

		if ( ! self::is_enabled() ) {
			self::redirect_back( 'gsc_premium_required' );
		}

		$sitemap_url = isset( $_POST['sitemap_url'] ) ? esc_url_raw( wp_unslash( $_POST['sitemap_url'] ) ) : '';
		if ( '' === $sitemap_url ) {
			self::redirect_back( 'gsc_submit_no_url' );
		}

		// Bound the submission to this site — prevents an admin (or a CSRF
		// attacker who somehow gets a valid nonce) from submitting an
		// arbitrary URL to Search Console under the connected property.
		// Google would reject URLs outside the registered property, so this
		// is belt-and-braces, but defence-in-depth beats trusting Google
		// for a check we can make locally.
		if ( ! self::url_belongs_to_this_site( $sitemap_url ) ) {
			self::redirect_back( 'gsc_submit_foreign_url' );
		}

		$result = self::submit_sitemap( $sitemap_url );

		$notice = is_wp_error( $result ) ? 'gsc_submit_failed' : 'gsc_submit_ok';
		self::redirect_back( $notice );
	}

	/**
	 * Whether the given URL resolves to the current site.
	 *
	 * Compares host + path-prefix against `home_url()`. Used as a guard in
	 * {@see self::handle_submit()} so the Submit button can't be abused to
	 * push arbitrary third-party URLs to Search Console.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function url_belongs_to_this_site( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		$home = wp_parse_url( home_url( '/' ) );
		$cand = wp_parse_url( $url );
		if ( empty( $home['host'] ) || empty( $cand['host'] ) ) {
			return false;
		}

		if ( strtolower( $home['host'] ) !== strtolower( $cand['host'] ) ) {
			return false;
		}

		$home_path = isset( $home['path'] ) ? $home['path'] : '/';
		$cand_path = isset( $cand['path'] ) ? $cand['path'] : '/';

		// The candidate path must start with the home path — covers the
		// subdirectory-install case (home = `/wp/`).
		return 0 === strpos( $cand_path, rtrim( $home_path, '/' ) . '/' )
			|| $cand_path === $home_path;
	}

	/**
	 * Fetch the status of a submitted sitemap from the Search Console API.
	 *
	 * GETs `https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/sitemaps/{feedpath}`
	 * (the WmxSitemap resource). Response shape:
	 *
	 *     {
	 *         "path": "https://example.com/sitemap-news.xml",
	 *         "lastSubmitted": "2026-05-01T10:20:30Z",    // RFC 3339
	 *         "lastDownloaded": "2026-05-01T10:25:00Z",   // RFC 3339
	 *         "isPending": false,
	 *         "isSitemapsIndex": false,
	 *         "type": "sitemap",
	 *         "errors": "0",
	 *         "warnings": "0",
	 *         "contents": [
	 *             { "type": "web", "submitted": "10" }
	 *         ]
	 *     }
	 *
	 * Cached for 1 hour via a transient keyed on the URL, so opening the
	 * Diagnostics panel repeatedly doesn't burn through Search Console
	 * API quota (current limit: 1,200 requests / minute per project).
	 *
	 * @since 0.1.0
	 *
	 * @param string $sitemap_url Public URL of the sitemap.
	 * @param bool   $force_fresh When true, bypass the transient cache.
	 * @return array|\WP_Error Decoded WmxSitemap array, or a WP_Error explaining the failure.
	 */
	public static function get_sitemap_status( $sitemap_url, $force_fresh = false ) {
		$cache_key = 'xmlse_gsc_status_' . md5( (string) $sitemap_url );
		if ( ! $force_fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$token = self::ensure_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$cfg = self::get_config();
		if ( '' === $cfg['site_url'] ) {
			return new \WP_Error( 'gsc_no_site_url', __( 'Search Console site URL is not configured.', 'xml-sitemap-engines' ) );
		}

		$endpoint = sprintf(
			'https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps/%s',
			rawurlencode( $cfg['site_url'] ),
			rawurlencode( $sitemap_url )
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = (string) wp_remote_retrieve_body( $response );
			return new \WP_Error( 'gsc_status_http_' . $code, $body );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'gsc_status_parse_failed', __( 'Could not parse Search Console response.', 'xml-sitemap-engines' ) );
		}

		set_transient( $cache_key, $decoded, HOUR_IN_SECONDS );
		return $decoded;
	}

	/**
	 * PUT a sitemap URL to the Search Console API.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sitemap_url Full public URL of the sitemap.
	 * @return true|\WP_Error
	 */
	public static function submit_sitemap( $sitemap_url ) {
		$token = self::ensure_access_token();
		if ( is_wp_error( $token ) ) {
			self::record_submission( $sitemap_url, 0, (string) $token->get_error_message() );
			return $token;
		}

		$cfg = self::get_config();
		if ( '' === $cfg['site_url'] ) {
			return new \WP_Error( 'gsc_no_site_url', __( 'Search Console site URL is not configured.', 'xml-sitemap-engines' ) );
		}

		$endpoint = sprintf(
			'https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps/%s',
			rawurlencode( $cfg['site_url'] ),
			rawurlencode( $sitemap_url )
		);

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_submission( $sitemap_url, 0, $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// The API returns 200 on success with an empty body.
		if ( 200 === $code || 204 === $code ) {
			self::record_submission( $sitemap_url, $code, 'OK' );
			return true;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		self::record_submission( $sitemap_url, $code, $body );

		return new \WP_Error( 'gsc_submit_http_' . $code, $body );
	}

	// ------------------------------------------------------------------
	// Token + log storage
	// ------------------------------------------------------------------

	/**
	 * Read tokens, decoding whatever the write path encoded.
	 *
	 * @since 0.1.0
	 *
	 * @return array{access_token:string, refresh_token:string, expires_at:int, token_type:string}
	 */
	public static function read_tokens() {
		$raw = get_option( self::TOKENS_OPTION, '' );

		/**
		 * Filters the raw token payload before decoding. Lets a host wrap
		 * reads with their own cipher.
		 *
		 * @since 0.1.0
		 *
		 * @param string $raw Value as stored in the option.
		 */
		$raw = (string) apply_filters( 'xmlse_gsc_tokens_filter_read', $raw );

		if ( '' === $raw ) {
			return array(
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
				'token_type'    => 'Bearer',
			);
		}

		$json    = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- OAuth token encoding, not code obfuscation.
		$decoded = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
				'token_type'    => 'Bearer',
			);
		}

		return array(
			'access_token'  => isset( $decoded['access_token'] ) ? (string) $decoded['access_token'] : '',
			'refresh_token' => isset( $decoded['refresh_token'] ) ? (string) $decoded['refresh_token'] : '',
			'expires_at'    => isset( $decoded['expires_at'] ) ? (int) $decoded['expires_at'] : 0,
			'token_type'    => isset( $decoded['token_type'] ) ? (string) $decoded['token_type'] : 'Bearer',
		);
	}

	/**
	 * Write tokens to the option, encoded for the filter hook to optionally
	 * wrap in a host-specific cipher.
	 *
	 * @since 0.1.0
	 *
	 * @param array $tokens Token payload.
	 */
	public static function write_tokens( $tokens ) {
		$encoded = base64_encode( wp_json_encode( (array) $tokens ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth token encoding, not code obfuscation.

		/**
		 * Filters the encoded token payload before it hits the DB.
		 *
		 * @since 0.1.0
		 *
		 * @param string $encoded Base64 JSON payload.
		 */
		$encoded = (string) apply_filters( 'xmlse_gsc_tokens_filter_write', $encoded );

		update_option( self::TOKENS_OPTION, $encoded, false );
	}

	/**
	 * Append a submission attempt to the ring-buffer log.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sitemap_url URL that was submitted.
	 * @param int    $status      HTTP status code (0 for pre-request errors).
	 * @param string $message     Short message to display.
	 */
	public static function record_submission( $sitemap_url, $status, $message ) {
		$log = (array) get_option( self::LOG_OPTION, array() );

		array_unshift(
			$log,
			array(
				'url'     => (string) $sitemap_url,
				'time'    => time(),
				'status'  => (int) $status,
				'message' => (string) $message,
			)
		);

		$log = array_slice( $log, 0, self::LOG_MAX );
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Read the submission log.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{url:string, time:int, status:int, message:string}>
	 */
	public static function get_log() {
		$log = (array) get_option( self::LOG_OPTION, array() );
		return array_values( array_filter( $log, 'is_array' ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Capability guard for every admin-post handler.
	 *
	 * @since 0.1.0
	 */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'xml-sitemap-engines' ), 403 );
		}
	}

	/**
	 * Redirect the user back to the Search Console tab with a notice code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $notice Notice slug (e.g. 'gsc_connected').
	 */
	private static function redirect_back( $notice ) {
		$target = add_query_arg(
			array(
				'page'         => Sitemap_Settings::PAGE_SLUG,
				'tab'          => 'search_console',
				'xmlse_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $target );
		exit;
	}

	// ------------------------------------------------------------------
	// Phase 35.2 — auto-submit on publish
	// ------------------------------------------------------------------

	/**
	 * Hook for `transition_post_status`. Re-submits `/sitemap-news.xml` to
	 * Search Console when a news-eligible post freshly transitions into
	 * publish.
	 *
	 * Every gate must pass before the HTTP call fires:
	 *   1. Fresh publish (`publish` after anything ≠ `publish`).
	 *   2. `xmlse_advanced_enabled` filter true (premium gate).
	 *   3. Site visibility on (`blog_public = 1`).
	 *   4. `xmlse_news_tags.auto_submit_to_gsc` toggle truthy.
	 *   5. News sitemap toggle on in `xmlse_sitemaps`.
	 *   6. Post type in the news post-type whitelist; not
	 *      password-protected; not excluded by `_xmlse_news_exclude` or
	 *      the `xmlse_excluded` filter.
	 *   7. 24h rate guard per URL satisfied.
	 *   8. GSC is connected (refresh token present).
	 *
	 * The submit itself is idempotent — Google's docs explicitly say
	 * repeated submissions of the same sitemap URL do NOT accelerate
	 * polling. The rate guard therefore exists to reduce chatty HTTP noise
	 * rather than to placate Google.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post transitioning.
	 */
	public static function maybe_auto_submit_on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( 1 !== (int) get_option( 'blog_public', 1 ) ) {
			return;
		}
		if ( ! self::is_connected() ) {
			return;
		}

		$sitemaps = (array) get_option( 'xmlse_sitemaps', array() );
		if ( empty( $sitemaps['sitemap-news'] ) ) {
			return;
		}

		$news_tags = (array) get_option( 'xmlse_news_tags', array() );
		if ( empty( $news_tags['auto_submit_to_gsc'] ) ) {
			return;
		}

		$post_types = isset( $news_tags['post_type'] ) && is_array( $news_tags['post_type'] )
			? array_values( array_filter( $news_tags['post_type'], 'strlen' ) )
			: array( 'post' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		if ( ! empty( $post->post_password ) ) {
			return;
		}
		if ( '1' === (string) get_post_meta( $post->ID, '_xmlse_news_exclude', true ) ) {
			return;
		}
		if ( apply_filters( 'xmlse_excluded', false, (int) $post->ID ) ) {
			return;
		}

		$news_url = home_url( '/sitemap-news.xml' );
		if ( ! self::rate_budget_allows_submit( $news_url ) ) {
			return;
		}

		$result = self::submit_sitemap( $news_url );
		if ( true === $result ) {
			self::mark_auto_submitted( $news_url );
		}
	}

	/**
	 * Whether we may fire an auto-submit for the given URL right now.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Sitemap URL about to be submitted.
	 * @return bool
	 */
	public static function rate_budget_allows_submit( $url ) {
		$map  = (array) get_option( self::AUTO_SUBMIT_AT_OPTION, array() );
		$last = isset( $map[ $url ] ) ? (int) $map[ $url ] : 0;

		if ( 0 === $last ) {
			return true;
		}
		return ( time() - $last ) >= self::AUTO_SUBMIT_RATE_WINDOW;
	}

	/**
	 * Record a successful auto-submit so the rate guard can suppress
	 * follow-ups within the window.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Sitemap URL that was just submitted.
	 */
	public static function mark_auto_submitted( $url ) {
		$map         = (array) get_option( self::AUTO_SUBMIT_AT_OPTION, array() );
		$map[ $url ] = time();
		update_option( self::AUTO_SUBMIT_AT_OPTION, $map, false );
	}
}
