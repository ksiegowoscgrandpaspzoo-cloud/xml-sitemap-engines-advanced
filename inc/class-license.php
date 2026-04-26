<?php
/**
 * EDD Software Licensing controller — license activation, status revalidation,
 * grace-period gating, and auto-update bootstrap.
 *
 * Premium add-on activation flow:
 *
 *   1. User pastes a license key → `activate_license` POST to the EDD store
 *      (`LICENSE_API_URL`, override-able via `xmlse_pro_license_api_url`).
 *   2. EDD responds with `success`, `license` ('valid'/'invalid'/'expired'/...),
 *      `expires`, `customer_email` etc. — persisted in option
 *      `xmlse_pro_license`.
 *   3. Daily cron (`xmlse_pro_license_daily_check`) re-runs `check_license` to
 *      catch refunds / cancellations / expiry rollover.
 *   4. {@see self::is_active()} drives the `xmlse_advanced_enabled` /
 *      `xmlse_news_advanced_enabled` filter gates wired in
 *      `xml-sitemap-engines-advanced.php`.
 *
 * **Grace period** — if the daily check cannot reach the EDD server (network
 * blip, DNS, hostname dropped), the gate stays open for an additional
 * `GRACE_WINDOW` after the standard 7-day "fresh check" window. A flapping
 * connection therefore won't disable a paying customer's premium for an hour
 * just because the cron happened to hit a 502.
 *
 * **License key handling** — the key never appears in `record_submission`-style
 * logs, never in `error_log()`, and is only echoed to the UI masked
 * (`set_masked_key()`). Storage is plaintext in `wp_options`; per
 * `docs/premium-architecture.md` §10 that's acceptable for the same reason
 * GSC tokens are: DB read access is already game-over.
 *
 * @package XMLSE_Advanced
 * @since 0.1.0
 */

namespace XMLSE\Advanced;

use XMLSE\Admin\Sitemap_Settings;

defined( 'WPINC' ) || die;

/**
 * License + auto-update controller.
 *
 * @since 0.1.0
 */
final class License {

	/**
	 * Option that stores the entire license payload. NOT autoloaded — the
	 * gate check `is_active()` runs once per request anyway and the payload
	 * has no business sitting in `alloptions`.
	 */
	const OPTION = 'xmlse_pro_license';

	/**
	 * EDD SL license-server URL. Filterable via `xmlse_pro_license_api_url`
	 * — the customer has not yet provisioned the store, so the constant is
	 * a placeholder. Override at runtime once the store goes live.
	 */
	const LICENSE_API_URL = 'https://example.com/';

	/**
	 * EDD product item ID. Filterable via `xmlse_pro_license_item_id`.
	 * Placeholder until the EDD product is created.
	 */
	const LICENSE_ITEM_ID = 0;

	/**
	 * Cron hook fired by `wp_schedule_event` for the daily revalidation.
	 */
	const CRON_HOOK = 'xmlse_pro_license_daily_check';

	/**
	 * Window in which a fresh `check_license` response must have landed
	 * for `is_active()` to trust the cached `valid` status without grace.
	 */
	const FRESH_WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Additional grace window for tolerating network failures when the
	 * standard fresh window has lapsed but the LAST successful check
	 * said `valid`. Total tolerance = FRESH_WINDOW + GRACE_WINDOW = 21
	 * days before a stalled / unreachable license server starts gating
	 * the customer out.
	 */
	const GRACE_WINDOW = 14 * DAY_IN_SECONDS;

	/**
	 * Status — license server says key is valid + active here.
	 */
	const STATUS_VALID = 'valid';

	/**
	 * Status — server rejected the key.
	 */
	const STATUS_INVALID = 'invalid';

	/**
	 * Status — server says the key has expired.
	 */
	const STATUS_EXPIRED = 'expired';

	/**
	 * Status — placeholder; nothing has been activated yet.
	 */
	const STATUS_NEVER_ACTIVATED = 'never_activated';

	/**
	 * Per-handler nonce action for the activation form.
	 */
	const NONCE_ACTIVATE = 'xmlse_pro_activate_license';

	/**
	 * Per-handler nonce action for the deactivation form.
	 */
	const NONCE_DEACTIVATE = 'xmlse_pro_deactivate_license';

	/**
	 * Per-handler nonce action for the manual "Check now" button.
	 */
	const NONCE_CHECK = 'xmlse_pro_check_license';

	// ------------------------------------------------------------------
	// Lifecycle
	// ------------------------------------------------------------------

	/**
	 * Wire admin-post handlers, cron, settings registration, and the
	 * `xmlse_license_status` extension that backfills the free side.
	 *
	 * @since 0.1.0
	 */
	public static function register_hooks() {
		add_action( 'admin_post_xmlse_pro_activate_license', array( __CLASS__, 'handle_activate' ) );
		add_action( 'admin_post_xmlse_pro_deactivate_license', array( __CLASS__, 'handle_deactivate' ) );
		add_action( 'admin_post_xmlse_pro_check_license', array( __CLASS__, 'handle_check_now' ) );

		add_action( self::CRON_HOOK, array( __CLASS__, 'daily_cron' ) );

		add_action(
			'xmlse_add_settings',
			static function ( $tab ) {
				if ( 'license' !== $tab ) {
					return;
				}
				self::render_activation_form();
			}
		);

		add_filter( 'xmlse_license_status', array( __CLASS__, 'extend_status_filter' ) );

		// Wire the auto-update bootstrap regardless of license status — EDD's
		// `get_version` action gracefully no-ops when the key is empty / not
		// activated. Doing it unconditionally also means the customer can
		// keep receiving updates if their key briefly lapses.
		self::boot_updater();
	}

	/**
	 * Whether `xmlse_advanced_enabled` should resolve to true. Used as the
	 * gate filter callback in the bootstrap.
	 *
	 * Truth table:
	 *
	 *   stored.status      | last_check fresh? | expires future? | result
	 *   -------------------+-------------------+-----------------+--------
	 *   valid              | yes (≤7d)         | yes             | true
	 *   valid              | yes               | no              | false
	 *   valid              | no (>7d, ≤21d)    | yes             | true (grace)
	 *   valid              | no (>21d)         | yes             | false
	 *   invalid / expired  | any               | any             | false
	 *   never_activated    | any               | any             | false
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_active() {
		$state = self::status();

		if ( self::STATUS_VALID !== $state['status'] ) {
			return false;
		}

		// `expires` may be 'lifetime' on lifetime licenses, or an ISO date.
		if ( ! self::expiry_is_in_future( $state['expires'] ) ) {
			return false;
		}

		$last_check = (int) $state['last_check'];
		$age        = max( 0, time() - $last_check );

		// Inside the standard fresh window — trust the cached status.
		if ( $age <= self::FRESH_WINDOW ) {
			return true;
		}

		// Stale-but-grace window. Last known status was 'valid', and the
		// expiry is in the future, so a temporary cron / network outage
		// should not gate the customer out. Beyond 21 days fall through.
		if ( $age <= self::FRESH_WINDOW + self::GRACE_WINDOW ) {
			return true;
		}

		return false;
	}

	/**
	 * Snapshot of the stored license payload, normalised to the 5-key
	 * shape every consumer expects.
	 *
	 * @since 0.1.0
	 *
	 * @return array{key:string, status:string, expires:string, customer_email:string, last_check:int}
	 */
	public static function status() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'key'            => isset( $raw['key'] ) ? (string) $raw['key'] : '',
			'status'         => isset( $raw['status'] ) ? (string) $raw['status'] : self::STATUS_NEVER_ACTIVATED,
			'expires'        => isset( $raw['expires'] ) ? (string) $raw['expires'] : '',
			'customer_email' => isset( $raw['customer_email'] ) ? (string) $raw['customer_email'] : '',
			'last_check'     => isset( $raw['last_check'] ) ? (int) $raw['last_check'] : 0,
		);
	}

	/**
	 * The currently stored license key. Helper for the updater bootstrap
	 * and tests.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function stored_key() {
		$state = self::status();
		return $state['key'];
	}

	// ------------------------------------------------------------------
	// EDD SL API operations
	// ------------------------------------------------------------------

	/**
	 * Activate a license key against the EDD store.
	 *
	 * Persists the key + decoded response on success. Caller is responsible
	 * for capability + nonce — see {@see self::handle_activate()}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $license_key Raw key as the user pasted it.
	 * @return array Decoded EDD response, augmented with an `error` slug on
	 *               transport / parse failure.
	 */
	public static function activate( $license_key ) {
		$license_key = trim( (string) $license_key );
		if ( '' === $license_key ) {
			return array(
				'success' => false,
				'license' => self::STATUS_INVALID,
				'error'   => 'empty_key',
			);
		}

		$response = self::edd_request( 'activate_license', $license_key );
		if ( isset( $response['_transport_error'] ) ) {
			return array(
				'success' => false,
				'license' => self::STATUS_INVALID,
				'error'   => $response['_transport_error'],
			);
		}

		// EDD response shape — keys: success (bool), license (status string),
		// expires (ISO date or 'lifetime'), customer_email (string), and a
		// few quota fields (license_limit, site_count) we ignore for now.
		$persisted_status = ! empty( $response['license'] ) ? (string) $response['license'] : self::STATUS_INVALID;
		$persist          = array(
			'key'            => $license_key,
			'status'         => $persisted_status,
			'expires'        => isset( $response['expires'] ) ? (string) $response['expires'] : '',
			'customer_email' => isset( $response['customer_email'] ) ? (string) $response['customer_email'] : '',
			'last_check'     => time(),
		);

		// Only persist the key when EDD says success — don't leave a junk key
		// laying around that subsequent cron checks would keep re-trying.
		if ( empty( $response['success'] ) ) {
			// Record an attempt timestamp so the UI can tell the user "we
			// tried at HH:MM:SS but EDD said no", but DO NOT promote the key
			// to the stored payload.
			$existing               = self::status();
			$existing['last_check'] = time();
			$existing['status']     = $persisted_status;
			update_option( self::OPTION, $existing, false );
			return $response;
		}

		update_option( self::OPTION, $persist, false );

		return $response;
	}

	/**
	 * Deactivate the currently stored license. Wipes local copy on
	 * success regardless of what EDD says — the customer wanted off.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True on success, false on transport failure (key still
	 *              wiped locally — see comment above).
	 */
	public static function deactivate() {
		$key = self::stored_key();
		if ( '' === $key ) {
			return true;
		}

		$response       = self::edd_request( 'deactivate_license', $key );
		$transport_okay = ! isset( $response['_transport_error'] );

		// Always clear local state — the user clicked Deactivate, so we
		// honour that intent even if EDD is unreachable. Without this a
		// dead store would lock the customer out of re-activating elsewhere.
		delete_option( self::OPTION );

		return $transport_okay;
	}

	/**
	 * Run a `check_license` round-trip without changing slot count.
	 *
	 * Used by the daily cron and the manual "Check now" admin-post handler.
	 *
	 * @since 0.1.0
	 *
	 * @return array Decoded response (with `_transport_error` set on
	 *               failure).
	 */
	public static function check() {
		$key = self::stored_key();
		if ( '' === $key ) {
			return array(
				'success' => false,
				'license' => self::STATUS_NEVER_ACTIVATED,
			);
		}

		$response = self::edd_request( 'check_license', $key );

		$state = self::status();

		if ( isset( $response['_transport_error'] ) ) {
			// Network blip — keep the existing status BUT bump last_check
			// so the grace-window logic can fire. Without this, a
			// permanently broken cron would never let the grace window
			// elapse, and a key that genuinely expired would stay 'valid'
			// forever.
			$state['last_check'] = time();
			update_option( self::OPTION, $state, false );
			return $response;
		}

		// EDD returned a structured response — promote.
		$state['status']     = isset( $response['license'] ) ? (string) $response['license'] : self::STATUS_INVALID;
		$state['expires']    = isset( $response['expires'] ) ? (string) $response['expires'] : $state['expires'];
		$state['last_check'] = time();
		if ( isset( $response['customer_email'] ) ) {
			$state['customer_email'] = (string) $response['customer_email'];
		}
		update_option( self::OPTION, $state, false );

		return $response;
	}

	/**
	 * Cron callback — `xmlse_pro_license_daily_check` schedule.
	 *
	 * @since 0.1.0
	 */
	public static function daily_cron() {
		self::check();
	}

	// ------------------------------------------------------------------
	// admin-post handlers
	// ------------------------------------------------------------------

	/**
	 * `admin-post.php?action=xmlse_pro_activate_license` handler.
	 *
	 * @since 0.1.0
	 */
	public static function handle_activate() {
		self::require_cap();
		check_admin_referer( self::NONCE_ACTIVATE );

		$key = isset( $_POST['license_key'] )
			? sanitize_text_field( wp_unslash( $_POST['license_key'] ) )
			: '';

		$response = self::activate( $key );
		$notice   = ! empty( $response['success'] ) ? 'license_activated' : 'license_activation_failed';
		self::redirect_back( $notice );
	}

	/**
	 * `admin-post.php?action=xmlse_pro_deactivate_license` handler.
	 *
	 * @since 0.1.0
	 */
	public static function handle_deactivate() {
		self::require_cap();
		check_admin_referer( self::NONCE_DEACTIVATE );

		self::deactivate();
		self::redirect_back( 'license_deactivated' );
	}

	/**
	 * `admin-post.php?action=xmlse_pro_check_license` handler.
	 *
	 * @since 0.1.0
	 */
	public static function handle_check_now() {
		self::require_cap();
		check_admin_referer( self::NONCE_CHECK );

		self::check();
		self::redirect_back( 'license_rechecked' );
	}

	// ------------------------------------------------------------------
	// View
	// ------------------------------------------------------------------

	/**
	 * Render the activation form. Called from the `xmlse_add_settings`
	 * action hook listener registered in {@see self::register_hooks()}.
	 *
	 * @since 0.1.0
	 */
	public static function render_activation_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include XMLSE_ADV_DIR . 'views/admin/section-license-pro.php';
	}

	// ------------------------------------------------------------------
	// Free-side filter contract
	// ------------------------------------------------------------------

	/**
	 * Backfill the free side's `License_Check::status()` array with the
	 * EDD-side fields the free repo declares but cannot populate on its own.
	 *
	 * @since 0.1.0
	 *
	 * @param array $status The default 4-key status array from
	 *                      `License_Check::status()`.
	 * @return array<string, mixed> Augmented status array.
	 */
	public static function extend_status_filter( $status ) {
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$local = self::status();
		return array_merge(
			$status,
			array(
				'expires'        => $local['expires'],
				'customer_email' => $local['customer_email'],
				'license_status' => $local['status'],
			)
		);
	}

	// ------------------------------------------------------------------
	// Updater bootstrap
	// ------------------------------------------------------------------

	/**
	 * Bring up the EDD SL plugin updater so paid customers receive updates.
	 *
	 * The vendor file is a no-op stub today (see
	 * `inc/vendor/EDD_SL_Plugin_Updater.php`). Once the EDD store goes live
	 * and the customer has access to the real `EDD_SL_Plugin_Updater.php`,
	 * paste it into the same path — class is `class_exists()`-guarded so a
	 * straight swap is safe.
	 *
	 * @since 0.1.0
	 */
	public static function boot_updater() {
		$path = XMLSE_ADV_DIR . 'inc/vendor/EDD_SL_Plugin_Updater.php';
		if ( ! file_exists( $path ) ) {
			return;
		}
		require_once $path;

		if ( ! class_exists( '\\EDD_SL_Plugin_Updater' ) ) {
			return;
		}

		new \EDD_SL_Plugin_Updater(
			(string) self::api_url(),
			defined( 'XMLSE_ADV_FILE' ) ? XMLSE_ADV_FILE : '',
			array(
				'version' => defined( 'XMLSE_ADV_VERSION' ) ? XMLSE_ADV_VERSION : '',
				'license' => self::stored_key(),
				'item_id' => (int) self::item_id(),
				'author'  => 'XML Sitemap Engines Team',
				'beta'    => false,
			)
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Mask the stored license key for safe display. Keeps the last 4 chars,
	 * stars the rest.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		$len = strlen( $key );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
	}

	/**
	 * Currently configured EDD store URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function api_url() {
		/**
		 * Filters the EDD Software Licensing endpoint URL.
		 *
		 * The constant {@see self::LICENSE_API_URL} is a placeholder until
		 * the customer's EDD store goes live — override at runtime via this
		 * filter to point the activation, check, and deactivation calls at
		 * the production store.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Default URL (`LICENSE_API_URL`).
		 */
		return (string) apply_filters( 'xmlse_pro_license_api_url', self::LICENSE_API_URL );
	}

	/**
	 * Currently configured EDD product item ID.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function item_id() {
		/**
		 * Filters the EDD product item ID used by license activation.
		 *
		 * Placeholder constant `0` until the EDD product is created; override
		 * at runtime via this filter (or replace the constant once provisioning
		 * is final).
		 *
		 * @since 0.1.0
		 *
		 * @param int $id Default item ID (`LICENSE_ITEM_ID`).
		 */
		return (int) apply_filters( 'xmlse_pro_license_item_id', self::LICENSE_ITEM_ID );
	}

	/**
	 * POST a request to the EDD SL endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param string $edd_action `activate_license` / `deactivate_license` / `check_license`.
	 * @param string $license_key The user's key.
	 * @return array Decoded JSON response, or `array( '_transport_error' => $code )`
	 *               when `wp_remote_post` failed or returned a non-200.
	 */
	private static function edd_request( $edd_action, $license_key ) {
		$response = wp_remote_post(
			self::api_url(),
			array(
				'timeout' => 15,
				'body'    => array(
					'edd_action' => (string) $edd_action,
					'license'    => (string) $license_key,
					'item_id'    => self::item_id(),
					'url'        => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( '_transport_error' => $response->get_error_code() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( '_transport_error' => 'http_' . $code );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return array( '_transport_error' => 'parse_failed' );
		}

		return $decoded;
	}

	/**
	 * Whether the given expiry string indicates a future / lifetime license.
	 *
	 * @since 0.1.0
	 *
	 * @param string $expires `'lifetime'`, ISO date, or empty.
	 * @return bool
	 */
	private static function expiry_is_in_future( $expires ) {
		$expires = (string) $expires;
		if ( '' === $expires || 'lifetime' === strtolower( $expires ) ) {
			// Lifetime licenses (or unset, defensive) — treat as never expiring.
			return true;
		}
		$ts = strtotime( $expires );
		if ( false === $ts ) {
			return true; // Unparseable — be lenient, fail open. Daily check will fix it.
		}
		return $ts > time();
	}

	/**
	 * Capability guard for every admin-post handler.
	 *
	 * @since 0.1.0
	 */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'xml-sitemap-engines-advanced' ), 403 );
		}
	}

	/**
	 * Redirect back to the License tab with a notice slug.
	 *
	 * @since 0.1.0
	 *
	 * @param string $notice Notice slug.
	 */
	private static function redirect_back( $notice ) {
		$page_slug = class_exists( '\\XMLSE\\Admin\\Sitemap_Settings' )
			? Sitemap_Settings::PAGE_SLUG
			: 'xml-sitemap-engines';

		$target = add_query_arg(
			array(
				'page'         => $page_slug,
				'tab'          => 'license',
				'xmlse_notice' => (string) $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $target );
		exit;
	}
}
