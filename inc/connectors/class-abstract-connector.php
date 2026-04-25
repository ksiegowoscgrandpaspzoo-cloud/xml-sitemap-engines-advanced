<?php
/**
 * Abstract base for search-engine submission connectors.
 *
 * Each connector (Bing, Yandex, Baidu, Google already separate) owns
 * its own credential storage and submission log but shares the same
 * lifecycle:
 *
 *   1. `register_hooks()` wires admin-post handlers.
 *   2. `submit_sitemap( $url )` performs the engine-specific HTTP call.
 *   3. `record_submission()` appends to a ring-buffer log.
 *
 * Subclasses override at least `slug()`, `label()`, and the
 * `do_submit_sitemap()` template method.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Connectors;

defined( 'WPINC' ) || die;

/**
 * Shared plumbing for every premium submission connector.
 *
 * @since 0.1.0
 */
abstract class Abstract_Connector {

	/**
	 * Max entries kept in the per-connector submission log.
	 */
	const LOG_MAX = 20;

	/**
	 * Short slug used in option names and admin-post actions.
	 * e.g. 'bing', 'yandex', 'baidu'.
	 *
	 * @return string
	 */
	abstract public static function slug();

	/**
	 * Human-readable engine name for the admin UI.
	 *
	 * @return string
	 */
	abstract public static function label();

	/**
	 * Perform the engine-specific HTTP call.
	 *
	 * @param string $sitemap_url Absolute sitemap URL.
	 * @return true|\WP_Error
	 */
	abstract protected static function do_submit_sitemap( $sitemap_url );

	// ------------------------------------------------------------------
	// Shared lifecycle
	// ------------------------------------------------------------------

	/**
	 * Default wiring — admin-post handler for the Submit button.
	 * Subclasses may override if they need extra hooks (OAuth callback
	 * etc.).
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_action(
			'admin_post_xmlse_adv_' . static::slug() . '_submit',
			array( static::class, 'handle_submit' )
		);
	}

	/**
	 * Submit a sitemap URL through the connector.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sitemap_url Absolute URL.
	 * @return true|\WP_Error
	 */
	public static function submit_sitemap( $sitemap_url ) {
		if ( empty( $sitemap_url ) ) {
			return new \WP_Error( 'xmlse_adv_empty_url', __( 'Sitemap URL is empty.', 'xml-sitemap-engines-advanced' ) );
		}

		$result = static::do_submit_sitemap( $sitemap_url );

		if ( is_wp_error( $result ) ) {
			static::record_submission( $sitemap_url, 0, $result->get_error_message() );
		} else {
			static::record_submission( $sitemap_url, 200, 'OK' );
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Admin-post handler
	// ------------------------------------------------------------------

	/**
	 * Handle the Submit-now button click.
	 *
	 * @since 0.1.0
	 */
	public static function handle_submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'xml-sitemap-engines-advanced' ), 403 );
		}

		$nonce_action = 'xmlse_adv_' . static::slug() . '_submit';
		check_admin_referer( $nonce_action );

		$url = isset( $_POST['sitemap_url'] )
			? esc_url_raw( wp_unslash( $_POST['sitemap_url'] ) )
			: '';

		if ( '' === $url ) {
			static::redirect_back( 'submit_no_url' );
		}

		// Bound to current site (mirrors the GSC guard from Sprint 0.1).
		if ( ! self::url_belongs_to_this_site( $url ) ) {
			static::redirect_back( 'submit_foreign_url' );
		}

		$result = static::submit_sitemap( $url );
		static::redirect_back( is_wp_error( $result ) ? 'submit_failed' : 'submit_ok' );
	}

	// ------------------------------------------------------------------
	// Log storage
	// ------------------------------------------------------------------

	/**
	 * Option key that holds the per-connector log.
	 *
	 * @return string
	 */
	public static function log_option() {
		return 'xmlse_adv_' . static::slug() . '_log';
	}

	/**
	 * Read the submission log.
	 *
	 * @return array<int, array{url:string, time:int, status:int, message:string}>
	 */
	public static function get_log() {
		$log = (array) get_option( static::log_option(), array() );
		return array_values( array_filter( $log, 'is_array' ) );
	}

	/**
	 * Append to the ring-buffer log.
	 *
	 * @param string $url     Submitted URL.
	 * @param int    $status  HTTP-ish status code (0 for pre-HTTP errors).
	 * @param string $message Short message.
	 */
	public static function record_submission( $url, $status, $message ) {
		$log = (array) get_option( static::log_option(), array() );

		array_unshift(
			$log,
			array(
				'url'     => (string) $url,
				'time'    => time(),
				'status'  => (int) $status,
				'message' => (string) $message,
			)
		);

		$log = array_slice( $log, 0, static::LOG_MAX );
		update_option( static::log_option(), $log, false );
	}

	// ------------------------------------------------------------------
	// Helpers (shared with the GSC integration)
	// ------------------------------------------------------------------

	/**
	 * Whether the URL resolves to the current site — prevents an admin
	 * (or nonce-stealing attacker) from submitting arbitrary third-party
	 * URLs through the connector. Checks both host + path prefix.
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

		return 0 === strpos( $cand_path, rtrim( $home_path, '/' ) . '/' )
			|| $cand_path === $home_path;
	}

	/**
	 * Whether a given URL or bare domain string shares the current site's
	 * host. Looser than {@see self::url_belongs_to_this_site()} — only
	 * host-matching, no path comparison — so it's usable for "verified
	 * site URL" property fields that legitimately point at a parent path
	 * (e.g. a subfolder WP install verifies `example.com/` at the root).
	 *
	 * Accepts:
	 *   - Full URL: `https://example.com/some/path`
	 *   - Bare host: `example.com`
	 *   - Subdomain check: `www.example.com` ≠ `example.com` (intentional —
	 *     search engines treat them as separate properties).
	 *
	 * @since 0.1.0
	 *
	 * @param string $candidate Full URL or bare domain.
	 * @return bool
	 */
	public static function host_belongs_to_this_site( $candidate ) {
		$candidate = (string) $candidate;
		if ( '' === $candidate ) {
			return false;
		}

		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $home_host ) {
			return false;
		}

		// Full URL path — parse host.
		$cand_host = wp_parse_url( $candidate, PHP_URL_HOST );
		if ( null === $cand_host ) {
			// Not a parseable URL — assume bare host.
			$cand_host = $candidate;
		}
		$cand_host = strtolower( (string) $cand_host );
		// Strip leading scheme-like prefixes if the user pasted a scheme
		// without `://`.
		$cand_host = preg_replace( '#^(https?|ftp):?/*#', '', $cand_host );
		// Strip trailing slash / path.
		$cand_host = preg_replace( '#/.*$#', '', $cand_host );

		return '' !== $cand_host && $home_host === $cand_host;
	}

	/**
	 * Redirect back with a per-connector notice slug.
	 *
	 * @param string $code Notice slug.
	 */
	protected static function redirect_back( $code ) {
		$prefix = static::slug();
		$target = add_query_arg(
			array(
				'xmlse_notice' => 'adv_' . $prefix . '_' . $code,
			),
			wp_get_referer() ? wp_get_referer() : admin_url()
		);
		wp_safe_redirect( $target );
		exit;
	}
}
