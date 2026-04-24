<?php
/**
 * Unified "Engines" settings tab.
 *
 * Aggregates every submission connector (Google via the existing
 * Search Console integration + Bing + Yandex + Baidu) into one
 * admin screen with per-engine sub-cards. Avoids multiplying
 * top-level tabs.
 *
 * Flow:
 *   1. `xmlse_settings_tabs` filter adds the "Engines" tab to the
 *      free plugin's settings page.
 *   2. `admin_init` registers the connector-config options +
 *      sections + fields under the `xmlse_engines` option group.
 *   3. Each connector's field callback renders a small form with
 *      credentials + a Submit-now button.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Admin;

use XMLSE\Advanced\Connectors\Baidu;
use XMLSE\Advanced\Connectors\Bing;
use XMLSE\Advanced\Connectors\Yandex;

defined( 'WPINC' ) || die;

/**
 * Engines admin tab.
 *
 * @since 0.1.0
 */
final class Engines_Panel {

	/**
	 * Register hooks — tab + settings + renderer.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_filter( 'xmlse_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Inject the "Engines" tab between "Search Console" and "Advanced".
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $tabs Existing tabs.
	 * @return array<string, string>
	 */
	public static function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$label = esc_html__( 'Engines', 'xml-sitemap-engines-advanced' );
		$before = 'advanced';
		$out    = array();
		foreach ( $tabs as $slug => $current ) {
			if ( $slug === $before && ! isset( $out['engines'] ) ) {
				$out['engines'] = $label;
			}
			$out[ $slug ] = $current;
		}
		if ( ! isset( $out['engines'] ) ) {
			$out['engines'] = $label;
		}
		return $out;
	}

	/**
	 * Register the three connector-config options + render the tab body
	 * when the tab is active.
	 *
	 * @since 0.1.0
	 */
	public static function register_settings() {
		register_setting(
			'xmlse_engines',
			Bing::CONFIG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Bing::class, 'sanitize_config' ),
				'default'           => array(
					'api_key'  => '',
					'site_url' => '',
				),
			)
		);
		register_setting(
			'xmlse_engines',
			Yandex::CONFIG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Yandex::class, 'sanitize_config' ),
				'default'           => array(
					'client_id'     => '',
					'client_secret' => '',
					'site_url'      => '',
					'user_id'       => 0,
					'host_id'       => '',
				),
			)
		);
		register_setting(
			'xmlse_engines',
			Baidu::CONFIG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Baidu::class, 'sanitize_config' ),
				'default'           => array(
					'site'  => '',
					'token' => '',
				),
			)
		);

		add_settings_section(
			'xmlse_engines_main',
			esc_html__( 'Multi-engine submissions', 'xml-sitemap-engines-advanced' ),
			array( __CLASS__, 'render_intro' ),
			'xmlse_engines'
		);
		add_settings_field(
			'xmlse_engines_view',
			esc_html__( 'Engines', 'xml-sitemap-engines-advanced' ),
			array( __CLASS__, 'render_body' ),
			'xmlse_engines',
			'xmlse_engines_main'
		);
	}

	/**
	 * Render intro copy.
	 *
	 * @since 0.1.0
	 */
	public static function render_intro() {
		echo '<p>';
		esc_html_e(
			'Submit any enabled sitemap URL to Bing, Yandex, and Baidu in addition to Google. Each engine uses its own credential scheme — fill whichever you actually need; unused engines stay inert. The Google connector lives on the Search Console tab.',
			'xml-sitemap-engines-advanced'
		);
		echo '</p>';
	}

	/**
	 * Render the body — per-engine cards.
	 *
	 * @since 0.1.0
	 */
	public static function render_body() {
		include XMLSE_ADV_DIR . 'views/admin/engines-panel.php';
	}
}
