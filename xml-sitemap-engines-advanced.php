<?php
/**
 * Plugin Name:       XML Sitemap Engines — News Advanced
 * Plugin URI:        https://wordpress.org/plugins/xml-sitemap-engines/
 * Description:       Premium add-on for XML Sitemap Engines. Adds Google Search Console integration, Bing / Yandex / Baidu connectors, category blacklist, 1,000-URL split, and bulk editor controls.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  xml-sitemap-engines
 * Author:            XML Sitemap Engines Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       xml-sitemap-engines-advanced
 * Domain Path:       /languages
 *
 * @package XMLSE_Advanced
 */

defined( 'WPINC' ) || die;

define( 'XMLSE_ADV_VERSION', '0.1.0' );
define( 'XMLSE_ADV_FILE', __FILE__ );
define( 'XMLSE_ADV_DIR', plugin_dir_path( __FILE__ ) );
define( 'XMLSE_ADV_URL', plugin_dir_url( __FILE__ ) );
define( 'XMLSE_ADV_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-style autoloader for classes under the `XMLSE\Advanced\` namespace.
 *
 * File conventions (mirrors the free plugin):
 *   XMLSE\Advanced\Foo_Bar          → inc/class-foo-bar.php
 *   XMLSE\Advanced\Admin\Foo_Bar    → inc/admin/class-foo-bar.php
 *   XMLSE\Advanced\Connectors\Foo   → inc/connectors/class-foo.php
 *
 * @since 0.1.0
 *
 * @param string $class FQCN to load.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'XMLSE\\Advanced\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'XMLSE\\Advanced\\' ) );
		$parts    = explode( '\\', $relative );
		$base     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $base ) ) . '.php';

		$path = XMLSE_ADV_DIR . 'inc/';
		if ( ! empty( $parts ) ) {
			$path .= strtolower( implode( '/', $parts ) ) . '/';
		}

		$full = $path . $file;
		if ( file_exists( $full ) ) {
			require_once $full;
		}
	}
);

/**
 * Boot the add-on on `plugins_loaded` priority 10 — after the free
 * plugin's facade (priority 9) but before `init`.
 *
 * The add-on declares its capability by flipping two filters:
 *   - `xmlse_advanced_enabled`      → global premium gate (used by
 *      Premium_Lock + maybe_auto_submit_on_publish + etc.)
 *   - `xmlse_news_advanced_enabled` → news-specific premium gate
 *      (reserved for finer scoping if ever needed)
 *
 * Sub-feature classes are registered inside the boot callback.
 *
 * @since 0.1.0
 */
add_action(
	'plugins_loaded',
	function () {
		// Hard requirement on the free base plugin.
		if ( ! function_exists( 'xmlse' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					esc_html_e(
						'XML Sitemap Engines — News Advanced requires the base plugin "XML Sitemap Engines" to be active.',
						'xml-sitemap-engines-advanced'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		// Flip the premium gates. Add-on presence = premium on.
		add_filter( 'xmlse_advanced_enabled', '__return_true' );
		add_filter( 'xmlse_news_advanced_enabled', '__return_true' );

		// Register add-on components.
		if ( class_exists( 'XMLSE\\Advanced\\Admin\\GSC_Integration' ) ) {
			\XMLSE\Advanced\Admin\GSC_Integration::register_hooks();
		}

		// Multi-engine submission connectors (Sprint 2).
		$xmlse_adv_connectors = array(
			'XMLSE\\Advanced\\Connectors\\Bing',
			'XMLSE\\Advanced\\Connectors\\Yandex',
			'XMLSE\\Advanced\\Connectors\\Baidu',
		);
		foreach ( $xmlse_adv_connectors as $xmlse_adv_connector ) {
			if ( class_exists( $xmlse_adv_connector ) ) {
				call_user_func( array( $xmlse_adv_connector, 'register_hooks' ) );
			}
		}

		// Override the Search Console tab views — swap the free-tier
		// Premium_Lock stub with the real OAuth wizard shipped by the
		// add-on.
		add_filter(
			'xmlse_field_gsc_view',
			function () {
				return XMLSE_ADV_DIR . 'views/admin/field-gsc.php';
			}
		);
		add_filter(
			'xmlse_section_search_console_view',
			function () {
				return XMLSE_ADV_DIR . 'views/admin/section-search-console.php';
			}
		);

		// Register the Settings API bindings the free repo dropped
		// in Sprint 1 — the options + sanitiser live with the real
		// class, not with the free-tier stub.
		add_action(
			'admin_init',
			function () {
				register_setting(
					'xmlse_search_console',
					'xmlse_gsc_config',
					array(
						'type'              => 'array',
						'sanitize_callback' => array( 'XMLSE\\Advanced\\Admin\\GSC_Integration', 'sanitize_config' ),
						'default'           => array(
							'client_id'     => '',
							'client_secret' => '',
							'site_url'      => '',
						),
					)
				);
			}
		);
	},
	10
);
