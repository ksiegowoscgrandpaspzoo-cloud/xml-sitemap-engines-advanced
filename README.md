# XML Sitemap Engines — News Advanced

Premium add-on for the free
[XML Sitemap Engines](https://wordpress.org/plugins/xml-sitemap-engines/)
plugin.

## What this add-on unlocks

- **Google Search Console integration** — BYO OAuth + manual Submit
  button + auto-submit on publish + status readout.
- **Multi-engine connectors** — Bing Webmaster API, Yandex Webmaster
  API, Baidu push API. Unified submissions panel showing all four
  engines at once.
- **Advanced content filters** — category blacklist, post-type
  exclusion, bulk-edit controls.
- **1,000-URL split** — news-sitemap index referencing one file per
  day (`/sitemap-news-YYYY-MM-DD.xml`) when the 48-hour window
  contains more than Google's 1,000-URL cap.
- **Custom XSL themes** — colour palette picker for the front-end
  sitemap preview.
- **Priority / changefreq advanced overrides** — per-post-type
  defaults beyond the per-post meta controls in the free tier.

## Architecture

The free base plugin exposes feature gates via two filters:

- `xmlse_advanced_enabled` — global premium flag.
- `xmlse_news_advanced_enabled` — news-specific premium flag
  (reserved for finer scoping).

This add-on flips both to `true` on `plugins_loaded:10` (after the
free facade boots at priority 9). Free-tier code respects the filters
and enables its premium-gated UI + hooks when they return true.

Add-on classes live in the `XMLSE\Advanced\` namespace, autoloaded
from `inc/` via the PSR-4-style loader in the main plugin file.

## Status

**0.1.0** — bootstrap + GSC integration extracted from the free
repo. Multi-engine connectors (Bing/Yandex/Baidu), bulk editor, XSL
themes, and the 1,000-URL split implementation are on the roadmap.

## License

GPL-2.0-or-later.
