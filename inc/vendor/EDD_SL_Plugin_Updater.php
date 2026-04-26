<?php
/**
 * EDD Software Licensing — Plugin Updater (STUB).
 *
 * The canonical class is shipped with the Easy Digital Downloads "Software
 * Licensing" extension and historically distributed at
 * <https://github.com/easydigitaldownloads/EDD-License-handler>. Both that
 * repo (now at `awesomemotive/EDD-License-handler`) and the EDD core repo
 * presently host only a README; the actual file is bundled with the EDD SL
 * extension's release zip and is not offered as a standalone download.
 *
 * TODO: paste real EDD_SL_Plugin_Updater.php here once the EDD store is
 *       provisioned and the customer has access to the EDD SL distribution.
 *       Replace this stub wholesale — keep only the file path
 *       (`inc/vendor/EDD_SL_Plugin_Updater.php`) and the `require_once` call
 *       in `inc/class-license.php`.
 *
 * For now this stub provides a no-op replica of the public surface
 * (`__construct` / `init` / `update_check` / `plugins_api_filter`) so the
 * test suite can require_once the file and so production deploys do not
 * fatal when {@see XMLSE\Advanced\License::register_hooks()} instantiates
 * the updater. Auto-update functionality is therefore disabled until the
 * real file is dropped in.
 *
 * Class is GUARDED with `class_exists()` so swapping in the real file from
 * EDD (which declares the same class without a guard) does not cause a
 * redeclaration fatal.
 *
 * @package XMLSE_Advanced
 */

defined( 'WPINC' ) || die;

if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) ) {

	/**
	 * No-op stub for the canonical EDD Software Licensing updater.
	 *
	 * Mirrors the constructor signature and the two `init`-registered
	 * filters of the real class so the rest of the codebase can compile,
	 * load, and invoke it without a live EDD SL endpoint.
	 *
	 * @since 0.1.0
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Squiz.Commenting.ClassComment, Generic.Files.OneObjectStructurePerFile.MultipleFound -- vendored third-party class name; required to match EDD's public API.
	class EDD_SL_Plugin_Updater {

		/**
		 * Stored constructor arguments.
		 *
		 * @var array<string, mixed>
		 */
		private $api_data;

		/**
		 * License server URL.
		 *
		 * @var string
		 */
		private $api_url;

		/**
		 * Plugin file path passed to `__construct`.
		 *
		 * @var string
		 */
		private $name;

		/**
		 * Constructor.
		 *
		 * @since 0.1.0
		 *
		 * @param string $_api_url     URL of the license server.
		 * @param string $_plugin_file Absolute path to the plugin's main file.
		 * @param array  $_api_data    Optional. Per-plugin data.
		 */
		public function __construct( $_api_url, $_plugin_file, $_api_data = null ) {
			$this->api_url  = trailingslashit( (string) $_api_url );
			$this->name     = (string) $_plugin_file;
			$this->api_data = is_array( $_api_data ) ? $_api_data : array();

			$this->init();
		}

		/**
		 * Register hooks. Real class wires `pre_set_site_transient_update_plugins`
		 * and `plugins_api`. Stub registers no-op callbacks so external code
		 * has hookable references.
		 *
		 * @since 0.1.0
		 */
		public function init() {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		}

		/**
		 * Filter callback for `pre_set_site_transient_update_plugins`.
		 *
		 * No-op — passes the transient through unchanged.
		 *
		 * @since 0.1.0
		 *
		 * @param mixed $_transient_data Transient value.
		 * @return mixed Unchanged transient value.
		 */
		public function update_check( $_transient_data ) {
			return $_transient_data;
		}

		/**
		 * Filter callback for `plugins_api`.
		 *
		 * No-op — passes the data through unchanged.
		 *
		 * @since 0.1.0
		 *
		 * @param mixed  $_data    Default data.
		 * @param string $_action  Requested action.
		 * @param mixed  $_args    Optional. Plugin API arguments.
		 * @return mixed Unchanged data value.
		 */
		public function plugins_api_filter( $_data, $_action = '', $_args = null ) {
			return $_data;
		}
	}
}
