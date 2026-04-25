# Changelog — XML Sitemap Engines: News Advanced

All notable changes to the premium add-on. Companion to the free
[xml-sitemap-engines](../xml-sitemap-engines/CHANGELOG.md) plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Nested `<form>` bug breaking Save changes on Search-engines tab.**
  Companion to the same fix in the free repo (`53c08d9`). `views/admin/engines-panel.php`
  and `views/admin/field-gsc.php` had 6 nested `<form action="admin-post.php">`
  blocks (Yandex disconnect / connect, GSC disconnect / connect, GSC sitemap submit,
  per-engine submit buttons). Both files are included from `add_settings_field`
  callbacks under the `xmlse_search_console` option group, so their DOM lives
  INSIDE the outer `<form action="options.php">` from `page-sitemap.php`. HTML5
  disallows nested forms; the browser closes the parent form at our inner
  `</form>`, orphaning the parent "Save changes" submit and breaking the entire
  tab's save flow. Replaced all six with `<a class="button">` links pointing at
  `admin-post.php?action=...` via `wp_nonce_url()`. `check_admin_referer()`
  works with GET nonces unchanged.

### Changed

- **Verified-site-URL fields readonly + auto-filled from `home_url()`.**
  Initial implementation accepted any URL the admin typed, defending against
  cross-domain misconfiguration via `host_belongs_to_this_site()` in the
  sanitiser. UX feedback: "easier to just lock the field and pre-fill it."
  Bing / Yandex / Baidu / GSC verified-site-URL fields now render as
  `<input type="url" readonly>` with `value=home_url()`. Host validation
  in `sanitize_config()` kept as belt-and-braces.

### Security

- **`host_belongs_to_this_site()` SSRF guard on every connector config.**
  Each connector's `sanitize_config()` validates that the verified-site-URL
  resolves to the same host as `home_url()`. Prevents an admin from
  configuring a connector to push someone else's domain (the engines would
  reject anyway, but locally enforced is cleaner — and the token / API key
  belongs paired with its intended domain).

### Added

- **Sprint 2 — Bing connector.** API-key auth (Bing Webmaster API
  endpoint), submission delegated to free-tier IndexNow (no duplicate
  HTTP). Status-lookup UI is shipped but empty pending GetUrlInfo
  integration (deferred).

- **Sprint 2 — Baidu Ziyuan push connector.** Long-lived bearer token
  (no OAuth), `POST data.zz.baidu.com/urls?site=<site>&token=<token>`
  with newline-separated URL body. Token sanitiser strips
  non-alphanumeric chars; site URL host-validated against
  `home_url()`.

- **Sprint 2 — Yandex Webmaster connector.** Full OAuth 2.0 BYO flow
  (Client ID / Client Secret created at oauth.yandex.com), token
  storage in non-autoloaded option, refresh on expiry. Submit endpoint
  `POST /v4.1/hosts/{host_id}/recrawl/queue`.

- **Sprint 2 — abstract `Connectors\Abstract_Connector` base.**
  Shared lifecycle: `slug()`, `label()`, `is_configured()`,
  `do_submit_sitemap()`, `host_belongs_to_this_site()` /
  `url_belongs_to_this_site()` SSRF helpers. Per-connector
  submission ring-buffer log (20 entries) keyed
  `xmlse_adv_<slug>_log`.

- **Sprint 2 — unified Engines settings panel.** `Admin\Engines_Panel`
  registers a `add_settings_field` callback on the shared
  `xmlse_search_console` section so all four connectors (GSC + Bing
  + Yandex + Baidu) render in a single tab. Tab name "Search engines"
  (free repo Sprint 1 renamed it from "Search Console").

- **Sprint 1 — bootstrapped premium add-on repository.** Header,
  PSR-4-style autoloader for `XMLSE\Advanced\` namespace,
  `composer.json`, `phpunit.xml.dist`, `phpcs.xml.dist`,
  `tests/unit/` skeleton.

- **Sprint 1 — moved `GSC_Integration` from free repo to premium
  repo.** The full OAuth controller (authorise → exchange → refresh
  → revoke) and Search Console API client (Submit, GetSitemap,
  ListSitemaps) live here. Free repo retains a `Premium_Lock`-wrapped
  stub view; premium plugin hooks `xmlse_field_gsc_view` and
  `xmlse_section_search_console_view` to swap in the real wizard at
  runtime. Token storage in non-autoloaded option, base64-encoded
  JSON by default with `xmlse_gsc_tokens_filter_read` /
  `_write` for hosts that want to add their own cipher.

[Unreleased]: https://github.com/REPLACE/xml-sitemap-engines-advanced/compare/main...HEAD
