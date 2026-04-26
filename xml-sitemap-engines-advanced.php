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

		// Flip the premium gates ONLY when the license server says the
		// site has a valid key (or is inside the grace window). Mere
		// presence of the add-on zip is not enough — paying customers
		// see premium, freeloaders see the locked free-tier UI.
		add_filter( 'xmlse_advanced_enabled', array( '\\XMLSE\\Advanced\\License', 'is_active' ) );
		add_filter( 'xmlse_news_advanced_enabled', array( '\\XMLSE\\Advanced\\License', 'is_active' ) );

		// License controller — owns activation flow, daily revalidation
		// cron, auto-update bootstrap, and the activation form rendered
		// into the free-side License tab via `xmlse_add_settings`.
		if ( class_exists( 'XMLSE\\Advanced\\License' ) ) {
			\XMLSE\Advanced\License::register_hooks();
		}

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

		// Unified "Engines" settings tab.
		if ( class_exists( 'XMLSE\\Advanced\\Admin\\Engines_Panel' ) ) {
			\XMLSE\Advanced\Admin\Engines_Panel::register_hooks();
		}

		// Bulk-edit "Sitemap: exclude/include" field (Sprint 3).
		if ( class_exists( 'XMLSE\\Advanced\\Admin\\Bulk_Edit' ) ) {
			\XMLSE\Advanced\Admin\Bulk_Edit::register_hooks();
		}

		// Custom XSL themes for the sitemap preview (Sprint 3).
		if ( class_exists( 'XMLSE\\Advanced\\Admin\\XSL_Themes' ) ) {
			\XMLSE\Advanced\Admin\XSL_Themes::register_hooks();
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

/**
 * Schedule the daily license revalidation cron at activation, drop it at
 * deactivation. Live `XMLSE\Advanced\License::CRON_HOOK` constant lookups
 * would require the autoloader to have run, but `register_*_hook` callbacks
 * fire BEFORE `plugins_loaded` — the literal cron-hook string is duplicated
 * here on purpose. Keep these two values in sync if `License::CRON_HOOK`
 * ever moves.
 *
 * @since 0.1.0
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'xmlse_pro_license_daily_check' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'xmlse_pro_license_daily_check' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'xmlse_pro_license_daily_check' );
	}
);
