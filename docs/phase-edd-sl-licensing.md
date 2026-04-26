# Phase — EDD Software Licensing integration

## Context

Until this phase the premium add-on flipped `xmlse_advanced_enabled`
unconditionally on activation: anyone with the zip got the full premium
feature surface. To ship to paying customers we need a license-key
activation flow against an [EDD Software
Licensing](https://easydigitaldownloads.com/docs/software-licensing-api/)
endpoint, daily revalidation cron, and an auto-update hook so customers
receive updates while their license stays current.

The free side has already shipped its receiving layer in phase A5 —
`License_Check::status()` reads the `xmlse_premium_version_string` and
`xmlse_license_status` filters. This phase populates those filters from
the premium side.

## What

1. **License controller (`XMLSE\Advanced\License`)** — final class
   exposing `is_active()`, `status()`, `activate()`, `deactivate()`,
   `check()`, `daily_cron()`, `render_activation_form()`. Backed by a
   single non-autoloaded option `xmlse_pro_license` storing the 5-key
   shape `key / status / expires / customer_email / last_check`.
2. **Bootstrap gate flip** — `xmlse_advanced_enabled` and
   `xmlse_news_advanced_enabled` are now wired to
   `License::is_active()` instead of `__return_true`. Mere zip presence
   no longer unlocks premium.
3. **Daily revalidation cron** — `xmlse_pro_license_daily_check`
   scheduled at activation, cleared at deactivation, runs
   `License::check()` once per day to catch refunds / cancellations /
   expiry.
4. **Grace period** — when the daily check cannot reach the EDD server
   (network blip, DNS, store maintenance), `is_active()` keeps the gate
   open for an additional 14 days past the standard 7-day fresh window.
   A flapping connection will not disable a paying customer's premium
   for an hour just because cron happened to hit a 502. After 21 total
   days of unanswered checks, the gate closes.
5. **EDD SL Plugin Updater bootstrap** — currently a no-op stub
   (`inc/vendor/EDD_SL_Plugin_Updater.php`). The `class_exists()` guard
   means a straight file swap to the real EDD distribution is safe.
6. **License activation form view** — rendered into the free plugin's
   License tab via the `xmlse_add_settings` action hook. Three states:
   never activated (key input + Activate), active (masked key + status
   badge + Deactivate + Check now), inactive/expired (same plus warning
   colour).
7. **Free-side filter contract** — `extend_status_filter()` adds
   `expires`, `customer_email`, `license_status` to whatever
   `License_Check::status()` produced, so the free-side License tab
   badge can render meaningful data once the license is activated.

## Files

- `inc/class-license.php` — new. The full controller.
- `inc/vendor/EDD_SL_Plugin_Updater.php` — new no-op stub.
  `TODO: paste real EDD_SL_Plugin_Updater.php here` once the EDD store
  goes live; the customer can swap in their copy from the EDD SL
  extension's release zip without changing anything else.
- `views/admin/section-license-pro.php` — new. Activation form view
  rendered through `xmlse_add_settings`.
- `xml-sitemap-engines-advanced.php` — gates flipped, License class
  registered in the `plugins_loaded` callback, cron registration added
  via `register_activation_hook` / `register_deactivation_hook`.
- `tests/bootstrap.php` — defines `XMLSE_ADV_DIR` / `XMLSE_ADV_FILE` /
  `XMLSE_ADV_VERSION` for the test runtime, requires
  `inc/class-license.php`, adds `WP_Error::get_error_code()` to the
  WP-core stub.
- `tests/unit/LicenseTest.php` — new. 28 cases covering the truth
  table, persistence rules, transport-error handling, mask helper, and
  filter extension surface.

## Hooks exported / consumed

Filters (premium-defined):

- `xmlse_pro_license_api_url` — override the EDD store URL. Default is
  the placeholder `LICENSE_API_URL` constant
  (`https://example.com/`); customer fills in once the store is live.
- `xmlse_pro_license_item_id` — override the EDD product item ID.
  Default `0` until the EDD product is created.

Actions (premium-defined):

- `xmlse_pro_license_daily_check` — cron hook fired daily.
- `admin_post_xmlse_pro_activate_license` — activation form POST handler.
- `admin_post_xmlse_pro_deactivate_license` — deactivation GET handler.
- `admin_post_xmlse_pro_check_license` — manual "Check now" handler.

Filters consumed (free-side):

- `xmlse_advanced_enabled` / `xmlse_news_advanced_enabled` — premium
  callback `License::is_active()`.
- `xmlse_license_status` — premium callback
  `License::extend_status_filter()` adds
  `expires` / `customer_email` / `license_status` keys.
- `xmlse_add_settings` — listener checks for `'license'` tab and
  renders `views/admin/section-license-pro.php`.

## Tests

- `tests/unit/LicenseTest.php` — 28 cases, all green.
  - `is_active` truth table: no key, fresh-and-valid, grace-period,
    grace-lapsed, expired status, expired date, lifetime expiry.
  - `activate` posts correct body, persists on success, does NOT
    persist on `success=false`, returns transport error on `WP_Error`,
    rejects empty key.
  - `deactivate` clears stored key on success and on transport
    failure, no-op when no key stored.
  - `check` updates last_check, returns never-activated when no key,
    bumps last_check on transport failure (so grace can lapse),
    promotes server-side `expired` into stored state.
  - `status` returns the 5-key shape, normalises non-array option.
  - `extend_status_filter` merges EDD fields, handles non-array input
    defensively.
  - `mask_key` keeps last 4 chars; handles edge-case short keys.
  - `stored_key`, `api_url`, `item_id` filterable surfaces.

Suite total: **106 tests / 182 assertions** (was 78 / 119).

## Acceptance

- Bootstrap gate flips only when `License::is_active()` returns true.
- Daily cron registered at activation, cleared at deactivation.
- Free side `License_Check::status()` array now contains
  `expires` / `customer_email` / `license_status` keys via the
  premium-side filter callback.
- License key is stored encrypted plaintext but never logged, never
  echoed unmasked, and only persisted on EDD `success=true` response.
- `composer test` green; `composer lint` clean on touched files (the
  pre-existing warnings in `xml-sitemap-engines-advanced.php` line 40
  and `views/admin/engines-panel.php` were not introduced by this PR).

## What is NOT done

- **Real EDD SL Plugin Updater file** — vendored as a no-op stub
  because the canonical class is bundled with the EDD SL paid
  extension and is not currently distributed standalone (the
  historical `easydigitaldownloads/EDD-License-handler` GitHub
  repo, now mirrored at `awesomemotive/EDD-License-handler`,
  presently hosts only a README; the file lives inside the EDD SL
  extension zip). Drop the real file at
  `inc/vendor/EDD_SL_Plugin_Updater.php` when the store goes live —
  the existing class is `class_exists()`-guarded so a straight swap
  works. Auto-update is therefore a no-op until then.
- **EDD store URL + item ID** — `LICENSE_API_URL` constant is the
  placeholder `https://example.com/`, `LICENSE_ITEM_ID` is `0`. Both
  are filterable (`xmlse_pro_license_api_url` /
  `xmlse_pro_license_item_id`); fill in once the EDD store is
  provisioned. A `define()` in `wp-config.php` paired with a tiny
  `add_filter()` hook is the typical override pattern.
- **EDD endpoint integration tests** — the Brain\Monkey unit suite
  mocks `wp_remote_post`. No live integration test exists yet because
  there is no EDD store to point at. Add a smoke test
  (`tests/integration/LicenseStoreTest.php`) once the store is up.
- **License key encryption at rest** — stored as plaintext in
  `wp_options` (matching `XMLSE\Advanced\Admin\GSC_Integration` token
  storage). DB read access is already game-over per the same threat
  model used for the GSC tokens. Hosts that want stronger storage can
  filter the option name through their own cipher layer; we did not
  add a `xmlse_pro_license_filter_read/write` filter pair in this
  phase but it is a clean extension if requested.
- **Plugin version bump** — `XMLSE_ADV_VERSION` stays at `0.1.0`. The
  free-side `License_Check::is_premium_outdated()` check needs the
  free repo's matching `XMLSE_ADV_MIN_VERSION`; bumping here would
  flip the outdated-warning banner without coordination.

## Links

- EDD SL HTTP API: <https://easydigitaldownloads.com/docs/software-licensing-api/>
- Premium architecture spec: free repo `docs/premium-architecture.md`
  §4 (License system) + §7 (License client skeleton).
- Free-side phase doc: free repo `docs/phase-a5-license-skeleton.md`.
- Free-side reader class: free repo `inc/class-license-check.php`.
- Free-side license tab view: free repo `views/admin/section-license.php`
  (fires `do_action( 'xmlse_add_settings', 'license' )`).
