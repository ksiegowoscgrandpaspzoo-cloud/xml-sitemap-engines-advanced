<?php
/**
 * Bing / Yandex / Baidu connector section inside the unified
 * "Search engines" tab.
 *
 * Since the "merge Search Console + Engines into one tab" refactor
 * this class no longer owns its own tab — it just registers a second
 * settings field under the free plugin's existing `xmlse_search_console`
 * section, below the Google wizard. One form, one save button, one
 * submission log story across all four engines.
 *
 * @package XMLSE_Advanced
 */

namespace XMLSE\Advanced\Admin;

use XMLSE\Advanced\Connectors\Baidu;
use XMLSE\Advanced\Connectors\Bing;
use XMLSE\Advanced\Connectors\Yandex;

defined( 'WPINC' ) || die;

/**
 * Bing + Yandex + Baidu section of the Search engines tab.
 *
 * @since 0.1.0
 */
final class Engines_Panel {

	/**
	 * Register hooks — setting registration + second field on the
	 * free plugin's Search engines tab.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ), 20 );
	}

	/**
	 * Register the three connector-config options + add a second
	 * settings field on the shared `xmlse_search_console` section.
	 *
	 * @since 0.1.0
	 */
	public static function register_settings() {
		register_setting(
			'xmlse_search_console',
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
			'xmlse_search_console',
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
			'xmlse_search_console',
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

		add_settings_field(
			'xmlse_engines_body',
			esc_html__( 'Other engines', 'xml-sitemap-engines-advanced' ),
			array( __CLASS__, 'render_body' ),
			'xmlse_search_console',
			'xmlse_search_console_main'
		);
	}

	/**
	 * Render the body — per-engine cards for Bing / Yandex / Baidu.
	 *
	 * @since 0.1.0
	 */
	public static function render_body() {
		include XMLSE_ADV_DIR . 'views/admin/engines-panel.php';
	}
}
