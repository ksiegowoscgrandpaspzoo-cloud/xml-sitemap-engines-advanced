<?php
/**
 * Baidu Ziyuan push API connector.
 *
 * Baidu's push API accepts a POST of newline-separated URLs to:
 *
 *     POST http://data.zz.baidu.com/urls?site=<site>&token=<token>
 *
 * `site` is the verified site URL (registered in Baidu Ziyuan
 * <https://ziyuan.baidu.com/>); `token` is the push token displayed
 * on the same page after site verification.
 *
 * The request body is plain text — each line a URL to push. For a
 * sitemap-submission scenario we just push the single sitemap URL;
 * Baidu then crawls it on its own schedule.
 *
 * No OAuth — the token acts as a long-lived bearer.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Connectors;

defined( 'WPINC' ) || die;

/**
 * Baidu push connector.
 *
 * @since 0.1.0
 */
final class Baidu extends Abstract_Connector {

	/**
	 * Option that stores the site URL + push token.
	 * Autoloaded — the values are short strings and we read them on
	 * every Submit-now click, not on every page load.
	 */
	const CONFIG_OPTION = 'xmlse_adv_baidu_config';

	/**
	 * {@inheritdoc}
	 */
	public static function slug() {
		return 'baidu';
	}

	/**
	 * {@inheritdoc}
	 */
	public static function label() {
		return __( 'Baidu (百度)', 'xml-sitemap-engines-advanced' );
	}

	/**
	 * Read the current configuration.
	 *
	 * @since 0.1.0
	 *
	 * @return array{site:string, token:string}
	 */
	public static function get_config() {
		$raw = get_option(
			self::CONFIG_OPTION,
			array(
				'site'  => '',
				'token' => '',
			)
		);
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'site'  => isset( $raw['site'] ) ? (string) $raw['site'] : '',
			'token' => isset( $raw['token'] ) ? (string) $raw['token'] : '',
		);
	}

	/**
	 * Whether the connector has enough credentials to fire a request.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['site'] && '' !== $cfg['token'];
	}

	/**
	 * Sanitize the config option — used when registered via Settings
	 * API. Kept on the same class as the connector so a single file
	 * owns the whole surface.
	 *
	 * @param mixed $input Raw value.
	 * @return array{site:string, token:string}
	 */
	public static function sanitize_config( $input ) {
		$out = array(
			'site'  => '',
			'token' => '',
		);
		if ( ! is_array( $input ) ) {
			return $out;
		}
		if ( isset( $input['site'] ) ) {
			$raw = esc_url_raw( trim( (string) $input['site'] ) );
			// Only accept if the host matches the current WordPress site.
			// Prevents an admin from configuring the plugin to push to a
			// domain they don't own — Baidu would reject anyway, but the
			// token belongs with its intended domain, so we refuse the
			// configuration locally.
			if ( '' !== $raw && ! self::host_belongs_to_this_site( $raw ) ) {
				$raw = '';
			}
			$out['site'] = $raw;
		}
		if ( isset( $input['token'] ) ) {
			$out['token'] = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $input['token'] );
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	protected static function do_submit_sitemap( $sitemap_url ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error(
				'xmlse_adv_baidu_not_configured',
				__( 'Baidu site URL and push token are not configured.', 'xml-sitemap-engines-advanced' )
			);
		}

		$cfg = self::get_config();

		$endpoint = add_query_arg(
			array(
				'site'  => $cfg['site'],
				'token' => $cfg['token'],
			),
			'http://data.zz.baidu.com/urls'
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'text/plain',
				),
				'body'    => (string) $sitemap_url,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// Baidu returns 200 + JSON `{"success": N, "remain": M}` on
		// accepted submissions, and `{"error": code, "message": "..."}`
		// on failure.
		$decoded = json_decode( $body, true );
		if ( 200 === $code && is_array( $decoded ) && isset( $decoded['success'] ) ) {
			return true;
		}

		$msg = '';
		if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
			$msg = (string) $decoded['message'];
		} elseif ( '' === $body ) {
			$msg = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP %d with empty body', 'xml-sitemap-engines-advanced' ),
				$code
			);
		} else {
			$msg = $body;
		}

		return new \WP_Error( 'xmlse_adv_baidu_submit_failed_' . $code, $msg );
	}
}
