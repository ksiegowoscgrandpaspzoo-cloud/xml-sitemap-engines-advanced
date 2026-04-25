<?php
/**
 * Bing Webmaster connector.
 *
 * Bing's long-standing sitemap-submission interface has been
 * consolidated around **IndexNow** since 2024 — the legacy
 * `bing.com/ping?sitemap=` endpoint returns 410 Gone, and Bing
 * Webmaster Tools itself recommends IndexNow for URL-level push.
 * The free base plugin already submits every published URL through
 * IndexNow (since Phase 11), so a premium Bing "submit" connector
 * would be redundant.
 *
 * What the premium Bing connector DOES add:
 *
 *   1. **Status readout** — the Bing Webmaster API still exposes
 *      `GetUrlInfo` + related endpoints that IndexNow alone does not
 *      surface. This lets us show Bing's own view of a submitted
 *      URL (index state, last-crawl date) in the Submissions panel.
 *   2. **API-key authentication** (simpler than OAuth/Azure AD).
 *      Users generate a Bing Webmaster API key at
 *      <https://www.bing.com/webmasters/apisettings> and paste it
 *      here — no OAuth dance needed.
 *
 * Submit semantics: we ACCEPT a sitemap URL in `do_submit_sitemap`
 * for parity with the other connectors, but internally delegate to
 * IndexNow (already wired in the free plugin). This keeps the UI
 * unified without duplicating the network call Bing asks us to make.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Connectors;

defined( 'WPINC' ) || die;

/**
 * Bing Webmaster connector.
 *
 * @since 0.1.0
 */
final class Bing extends Abstract_Connector {

	const CONFIG_OPTION = 'xmlse_adv_bing_config';

	const API_BASE = 'https://ssl.bing.com/webmaster/api.svc/json';

	/**
	 * {@inheritdoc}
	 */
	public static function slug() {
		return 'bing';
	}

	/**
	 * {@inheritdoc}
	 */
	public static function label() {
		return __( 'Bing', 'xml-sitemap-engines-advanced' );
	}

	/**
	 * @return array{api_key:string, site_url:string}
	 */
	public static function get_config() {
		$raw = get_option(
			self::CONFIG_OPTION,
			array(
				'api_key'  => '',
				'site_url' => '',
			)
		);
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$site_url = isset( $raw['site_url'] ) ? (string) $raw['site_url'] : '';
		// Auto-fill from home_url() when empty — the readonly UI form
		// always submits this value, but the option may be empty before
		// the first save.
		if ( '' === $site_url ) {
			$site_url = trailingslashit( (string) home_url() );
		}
		return array(
			'api_key'  => isset( $raw['api_key'] ) ? (string) $raw['api_key'] : '',
			'site_url' => $site_url,
		);
	}

	/**
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['api_key'] && '' !== $cfg['site_url'];
	}

	/**
	 * Sanitize the config payload.
	 *
	 * @param mixed $input Raw value.
	 * @return array{api_key:string, site_url:string}
	 */
	public static function sanitize_config( $input ) {
		$out = array(
			'api_key'  => '',
			'site_url' => '',
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}
		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $input['api_key'] );
		}
		if ( isset( $input['site_url'] ) ) {
			$raw = esc_url_raw( trim( (string) $input['site_url'] ) );
			if ( '' !== $raw && ! self::host_belongs_to_this_site( $raw ) ) {
				$raw = '';
			}
			$out['site_url'] = $raw;
		}
		return $out;
	}

	/**
	 * Delegate submit to IndexNow (already wired in the free plugin).
	 *
	 * Logs the delegation + IndexNow outcome to Bing's own log so users
	 * see a single timeline regardless of which transport actually
	 * pushed the URL.
	 *
	 * @param string $sitemap_url Absolute sitemap URL.
	 * @return true|\WP_Error
	 */
	protected static function do_submit_sitemap( $sitemap_url ) {
		if ( ! class_exists( '\\XMLSE\\IndexNow' ) ) {
			return new \WP_Error(
				'xmlse_adv_bing_no_indexnow',
				__( 'IndexNow class not found — is the free base plugin active?', 'xml-sitemap-engines-advanced' )
			);
		}

		$ok = \XMLSE\IndexNow::submit_url( $sitemap_url );
		if ( ! $ok ) {
			return new \WP_Error(
				'xmlse_adv_bing_indexnow_failed',
				__( 'IndexNow push failed — check the IndexNow log.', 'xml-sitemap-engines-advanced' )
			);
		}

		return true;
	}

	/**
	 * Ask Bing Webmaster for the indexation status of a URL.
	 *
	 * Endpoint (verified against public documentation — subject to
	 * schema drift; treat the decoded response as best-effort):
	 *
	 *     GET {API_BASE}/GetUrlInfo?apikey={key}&siteUrl={site}&url={url}
	 *
	 * Returns either the decoded JSON payload or a WP_Error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Absolute URL to look up.
	 * @return array|\WP_Error
	 */
	public static function get_url_status( $url ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error(
				'xmlse_adv_bing_not_configured',
				__( 'Bing API key and site URL are not configured.', 'xml-sitemap-engines-advanced' )
			);
		}

		$cfg = self::get_config();
		$endpoint = add_query_arg(
			array(
				'apikey'  => $cfg['api_key'],
				'siteUrl' => $cfg['site_url'],
				'url'     => $url,
			),
			self::API_BASE . '/GetUrlInfo'
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'xmlse_adv_bing_status_http_' . $code,
				(string) wp_remote_retrieve_body( $response )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'xmlse_adv_bing_status_parse',
				__( 'Could not parse Bing response.', 'xml-sitemap-engines-advanced' )
			);
		}
		return $body;
	}
}
