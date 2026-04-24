<?php
/**
 * Yandex Webmaster connector.
 *
 * Submits sitemap URLs via the Yandex Webmaster v4 API. OAuth 2.0 via
 * Yandex Passport — standard three-step dance:
 *
 *   1. User creates a Yandex OAuth application at
 *      <https://oauth.yandex.com/client/new> and pastes Client ID +
 *      Client Secret into the connector form.
 *   2. `handle_oauth_start()` redirects to Yandex's consent screen.
 *   3. `handle_oauth_callback()` exchanges the code for tokens and
 *      stores them in `xmlse_adv_yandex_tokens` (not autoloaded).
 *
 * Submit flow:
 *
 *   1. Resolve user_id:
 *      GET https://api.webmaster.yandex.net/v4/user/
 *   2. Resolve host_id for the configured site URL:
 *      GET https://api.webmaster.yandex.net/v4/user/{user_id}/hosts/
 *   3. Add sitemap:
 *      POST https://api.webmaster.yandex.net/v4/user/{user_id}/hosts/{host_id}/user-added-sitemaps/
 *      body: {"url": "<sitemap_url>"}
 *
 * user_id + host_id are cached in the config option after the first
 * submit so subsequent calls skip the discovery round-trips.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Connectors;

defined( 'WPINC' ) || die;

/**
 * Yandex Webmaster connector.
 *
 * @since 0.1.0
 */
final class Yandex extends Abstract_Connector {

	const CONFIG_OPTION   = 'xmlse_adv_yandex_config';
	const TOKENS_OPTION   = 'xmlse_adv_yandex_tokens';
	const STATE_TRANSIENT = 'xmlse_adv_yandex_state';

	const OAUTH_AUTHORIZE = 'https://oauth.yandex.com/authorize';
	const OAUTH_TOKEN     = 'https://oauth.yandex.com/token';
	const API_BASE        = 'https://api.webmaster.yandex.net/v4';

	/**
	 * {@inheritdoc}
	 */
	public static function slug() {
		return 'yandex';
	}

	/**
	 * {@inheritdoc}
	 */
	public static function label() {
		return __( 'Yandex (Яндекс)', 'xml-sitemap-engines-advanced' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function register_hooks() {
		parent::register_hooks();
		add_action( 'admin_post_xmlse_adv_yandex_oauth_start', array( __CLASS__, 'handle_oauth_start' ) );
		add_action( 'admin_post_xmlse_adv_yandex_oauth_callback', array( __CLASS__, 'handle_oauth_callback' ) );
		add_action( 'admin_post_xmlse_adv_yandex_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	// ------------------------------------------------------------------
	// Config accessors
	// ------------------------------------------------------------------

	/**
	 * @return array{client_id:string, client_secret:string, site_url:string, user_id:int, host_id:string}
	 */
	public static function get_config() {
		$raw = get_option(
			self::CONFIG_OPTION,
			array(
				'client_id'     => '',
				'client_secret' => '',
				'site_url'      => '',
				'user_id'       => 0,
				'host_id'       => '',
			)
		);
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'client_id'     => isset( $raw['client_id'] ) ? (string) $raw['client_id'] : '',
			'client_secret' => isset( $raw['client_secret'] ) ? (string) $raw['client_secret'] : '',
			'site_url'      => isset( $raw['site_url'] ) ? (string) $raw['site_url'] : '',
			'user_id'       => isset( $raw['user_id'] ) ? (int) $raw['user_id'] : 0,
			'host_id'       => isset( $raw['host_id'] ) ? (string) $raw['host_id'] : '',
		);
	}

	/**
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['client_id']
			&& '' !== $cfg['client_secret']
			&& '' !== $cfg['site_url'];
	}

	/**
	 * @return bool
	 */
	public static function is_connected() {
		$tokens = self::read_tokens();
		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * @param mixed $input
	 * @return array
	 */
	public static function sanitize_config( $input ) {
		$out = array(
			'client_id'     => '',
			'client_secret' => '',
			'site_url'      => '',
			'user_id'       => 0,
			'host_id'       => '',
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}
		if ( isset( $input['client_id'] ) ) {
			$out['client_id'] = trim( sanitize_text_field( (string) $input['client_id'] ) );
		}
		if ( isset( $input['client_secret'] ) ) {
			$out['client_secret'] = trim( wp_strip_all_tags( (string) $input['client_secret'] ) );
		}
		if ( isset( $input['site_url'] ) ) {
			$out['site_url'] = esc_url_raw( trim( (string) $input['site_url'] ) );
		}
		// user_id + host_id are auto-filled after the first submit; they
		// come from discovery round-trips, not from the form.
		$existing = self::get_config();
		$out['user_id'] = $existing['user_id'];
		$out['host_id'] = $existing['host_id'];

		return $out;
	}

	/**
	 * Build the exact OAuth redirect URI. User must paste this verbatim
	 * into their Yandex OAuth app's Redirect URI field.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=xmlse_adv_yandex_oauth_callback' );
	}

	// ------------------------------------------------------------------
	// OAuth flow
	// ------------------------------------------------------------------

	/**
	 * Start the OAuth dance. Yandex Passport requires no "scope" param
	 * for Webmaster access — the consent screen lists scopes registered
	 * on the OAuth app.
	 */
	public static function handle_oauth_start() {
		self::require_cap();
		check_admin_referer( 'xmlse_adv_yandex_oauth_start' );

		if ( ! self::is_configured() ) {
			self::redirect_back( 'oauth_not_configured' );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$cfg = self::get_config();
		$url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $cfg['client_id'],
				'redirect_uri'  => self::redirect_uri(),
				'state'         => $state,
			),
			self::OAUTH_AUTHORIZE
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth redirect.
		exit;
	}

	/**
	 * Exchange the code for tokens.
	 */
	public static function handle_oauth_callback() {
		self::require_cap();

		// OAuth2 state check (same pattern as GSC_Integration).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $err ) {
			self::redirect_back( 'oauth_denied' );
		}

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$expected = (string) get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );
		if ( '' === $code || '' === $state || $state !== $expected ) {
			self::redirect_back( 'oauth_state_mismatch' );
		}

		$cfg = self::get_config();
		$response = wp_remote_post(
			self::OAUTH_TOKEN,
			array(
				'timeout' => 10,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::redirect_back( 'oauth_exchange_failed' );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			self::redirect_back( 'oauth_exchange_failed' );
		}

		self::write_tokens(
			array(
				'access_token'  => (string) $body['access_token'],
				'refresh_token' => isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '',
				'expires_at'    => time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ),
			)
		);

		self::redirect_back( 'connected' );
	}

	public static function handle_disconnect() {
		self::require_cap();
		check_admin_referer( 'xmlse_adv_yandex_disconnect' );

		delete_option( self::TOKENS_OPTION );
		self::redirect_back( 'disconnected' );
	}

	/**
	 * Refresh the access token if needed; return a valid bearer or WP_Error.
	 *
	 * @return string|\WP_Error
	 */
	public static function ensure_access_token() {
		$tokens = self::read_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error(
				'xmlse_adv_yandex_not_connected',
				__( 'Yandex Webmaster connector is not connected.', 'xml-sitemap-engines-advanced' )
			);
		}

		if ( ! empty( $tokens['access_token'] ) && ( (int) $tokens['expires_at'] ) > time() + 60 ) {
			return (string) $tokens['access_token'];
		}

		$cfg = self::get_config();
		$response = wp_remote_post(
			self::OAUTH_TOKEN,
			array(
				'timeout' => 10,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => (string) $tokens['refresh_token'],
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error(
				'xmlse_adv_yandex_refresh_failed',
				(string) wp_remote_retrieve_body( $response )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new \WP_Error(
				'xmlse_adv_yandex_refresh_failed',
				__( 'Refresh response missing access_token.', 'xml-sitemap-engines-advanced' )
			);
		}

		self::write_tokens(
			array(
				'access_token'  => (string) $body['access_token'],
				'refresh_token' => (string) $tokens['refresh_token'],
				'expires_at'    => time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ),
			)
		);

		return (string) $body['access_token'];
	}

	// ------------------------------------------------------------------
	// Submit
	// ------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	protected static function do_submit_sitemap( $sitemap_url ) {
		$token = self::ensure_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$cfg = self::get_config();

		// Resolve user_id + host_id if we don't have them cached yet.
		if ( 0 === $cfg['user_id'] ) {
			$user_id = self::fetch_user_id( $token );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$cfg['user_id'] = (int) $user_id;
		}

		if ( '' === $cfg['host_id'] ) {
			$host_id = self::fetch_host_id( $token, $cfg['user_id'], $cfg['site_url'] );
			if ( is_wp_error( $host_id ) ) {
				return $host_id;
			}
			$cfg['host_id'] = (string) $host_id;
		}

		// Persist discovered IDs.
		update_option( self::CONFIG_OPTION, $cfg );

		$endpoint = sprintf(
			'%s/user/%d/hosts/%s/user-added-sitemaps/',
			self::API_BASE,
			$cfg['user_id'],
			rawurlencode( $cfg['host_id'] )
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'OAuth ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'url' => (string) $sitemap_url ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new \WP_Error(
			'xmlse_adv_yandex_submit_http_' . $code,
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * GET /v4/user/ — returns the authorising user's ID.
	 *
	 * @param string $token Bearer token.
	 * @return int|\WP_Error
	 */
	private static function fetch_user_id( $token ) {
		$response = wp_remote_get(
			self::API_BASE . '/user/',
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'OAuth ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['user_id'] ) ) {
			return new \WP_Error(
				'xmlse_adv_yandex_user_lookup_failed',
				(string) wp_remote_retrieve_body( $response )
			);
		}
		return (int) $body['user_id'];
	}

	/**
	 * GET /v4/user/{user_id}/hosts/ — find the host_id matching our site_url.
	 *
	 * @param string $token Bearer.
	 * @param int    $user_id User ID.
	 * @param string $site_url Configured site URL.
	 * @return string|\WP_Error Host ID on success.
	 */
	private static function fetch_host_id( $token, $user_id, $site_url ) {
		$response = wp_remote_get(
			sprintf( '%s/user/%d/hosts/', self::API_BASE, $user_id ),
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'OAuth ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['hosts'] ) ) {
			return new \WP_Error(
				'xmlse_adv_yandex_no_hosts',
				__( 'Yandex Webmaster account has no verified hosts.', 'xml-sitemap-engines-advanced' )
			);
		}

		$target_host = wp_parse_url( $site_url, PHP_URL_HOST );
		foreach ( $body['hosts'] as $host ) {
			if ( ! is_array( $host ) || empty( $host['host_id'] ) ) {
				continue;
			}
			$candidate = isset( $host['unicode_host_url'] ) ? (string) $host['unicode_host_url'] : '';
			$candidate = $candidate ? $candidate : ( isset( $host['ascii_host_url'] ) ? (string) $host['ascii_host_url'] : '' );
			if ( '' === $candidate ) {
				continue;
			}
			if ( strtolower( wp_parse_url( $candidate, PHP_URL_HOST ) ) === strtolower( (string) $target_host ) ) {
				return (string) $host['host_id'];
			}
		}

		return new \WP_Error(
			'xmlse_adv_yandex_host_not_verified',
			sprintf(
				/* translators: %s: site URL */
				__( 'No Yandex Webmaster host matches %s — verify the site in Yandex.Webmaster first.', 'xml-sitemap-engines-advanced' ),
				$site_url
			)
		);
	}

	// ------------------------------------------------------------------
	// Token storage (same base64-json pattern as GSC)
	// ------------------------------------------------------------------

	/**
	 * @return array{access_token:string, refresh_token:string, expires_at:int}
	 */
	public static function read_tokens() {
		$raw = get_option( self::TOKENS_OPTION, '' );
		if ( '' === $raw ) {
			return array(
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
			);
		}
		$json    = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- OAuth token encoding, not code obfuscation.
		$decoded = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
			);
		}
		return array(
			'access_token'  => isset( $decoded['access_token'] ) ? (string) $decoded['access_token'] : '',
			'refresh_token' => isset( $decoded['refresh_token'] ) ? (string) $decoded['refresh_token'] : '',
			'expires_at'    => isset( $decoded['expires_at'] ) ? (int) $decoded['expires_at'] : 0,
		);
	}

	/**
	 * @param array $tokens
	 */
	public static function write_tokens( $tokens ) {
		$encoded = base64_encode( wp_json_encode( (array) $tokens ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth token encoding, not code obfuscation.
		update_option( self::TOKENS_OPTION, $encoded, false );
	}

	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'xml-sitemap-engines-advanced' ), 403 );
		}
	}
}
