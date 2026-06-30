# Feedico Sync — WordPress plugin

> Sync **affiliate merchants** and **live coupon codes** from [Feedico](https://feedico.io) into WordPress — custom tables, optional post types, shortcodes, and Gutenberg blocks. No custom code required.

**Website:** [feedico.io](https://feedico.io) · **Documentation:** [feedico.io/docs](https://feedico.io/docs)

`feedico` · `wordpress` · `wordpress-plugin` · `coupons` · `merchants` · `affiliate` · `affiliate-marketing` · `coupon-api` · `gutenberg`

---

## What it does

**Feedico Sync** is the official WordPress integration for the Feedico publisher API. Point it at your account, pick affiliate networks, and the plugin keeps your site up to date:

| Layer | What you get |
|-------|----------------|
| **Sync** | Scheduled or on-demand import from CJ, Awin, Impact, and other networks via one Feedico feed |
| **Storage** | Dedicated DB tables for fast queries; optional mirror as `feedico_store` / `feedico_coupon` post types |
| **Front end** | Visitor-ready merchant grids, coupon lists, and per-store landing pages |
| **Admin** | Connection test, network picker, sync logs, merchant/coupon editors with manual-lock support |

Large catalogs use **time-sliced background sync** (v1.7.8+) so admin requests and WP-Cron ticks stay lightweight.

---

## Quick start

1. Copy this repo into `wp-content/plugins/feedico-sync/` (folder name must match).
2. **Plugins → Activate** “Feedico Sync”.
3. Open **Feedico Sync** in the admin menu.
4. Enter your Feedico **email**, **password**, and **API token** (`fdco_…` from your [dashboard](https://feedico.io)).
5. Select networks → **Save settings** → **Run sync now**.

Full walkthrough: [`docs/HOW-TO.md`](docs/HOW-TO.md) · PDF: [`docs/Feedico-Sync-How-To.pdf`](docs/Feedico-Sync-How-To.pdf)

---

## Requirements

| | Minimum |
|---|---------|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| PHP extension | OpenSSL recommended (credentials encryption) |
| Cron | WP-Cron or server cron hitting `wp-cron.php` for reliable sync |

---

## Show data on your site

### Shortcodes

```
[feedico_merchants per_page="24"]
[feedico_coupons per_page="24" merchant_id="123"]
[feedico_merchant_page merchant_id="123"]
```

Search and pagination use URL params (`fcm_q`, `fcm_p`, `fcc_q`, `fcc_p`, `fcc_mid`). See the [shortcode reference](docs/HOW-TO.md#19-front-end-shortcode-reference) in the how-to guide.

### Gutenberg blocks

| Block | Purpose |
|-------|---------|
| `feedico/merchants` | Merchant grid |
| `feedico/coupons` | Coupon grid (optional merchant filter + search) |
| `feedico/merchant-page` | Store hero + coupons for one merchant |

---

## Features at a glance

- **Background sync** — “Run sync now” and network changes queue work via WP-Cron instead of blocking the browser
- **Time-sliced runs** — each request processes a slice (~22s, filter `feedico_sync_slice_max_seconds`) then resumes on the next tick
- **Encrypted credentials** — email, password, and token stored with OpenSSL when available
- **Privacy API** — export/erase hooks for stored account data
- **SEO defaults** — `noindex` on Feedico CPT singles/archives (filter: `feedico_sync_cpt_noindex`)
- **Safe uninstall** — data kept by default; opt-in full wipe with `FEEDICO_SYNC_DELETE_DATA` in `wp-config.php`

---

## Local development

```bash
git clone https://github.com/feedico-io/feedico-wp-plugin.git feedico-sync
# deploy to a local WordPress plugins directory:
./wpreal
# or:
./deploy-to-wordpress.sh
```

`wpreal` rsyncs this tree to `/var/www/wordpress/wp-content/plugins/feedico-sync` by default. Override with `WPREAL_DEST=/your/path wpreal`.

---

## API & custom integrations

This plugin uses the same REST surface documented at **[feedico.io/docs](https://feedico.io/docs)**:

- `POST /api/v1/me/networks` — merchants
- `POST /api/v1/me/coupons` — coupons

Building outside WordPress? Use our language starters:

| Repo | Stack |
|------|--------|
| [feedico-api-php-example](https://github.com/feedico-io/feedico-api-php-example) | PHP / cURL |
| [feedico-api-csharp-example](https://github.com/feedico-io/feedico-api-csharp-example) | C# / .NET |
| **This repo** | WordPress |

---

## Uninstall & data removal

By default, uninstall **keeps** custom tables and CPT posts. To delete everything when removing the plugin, add to `wp-config.php` **before** uninstall:

```php
define( 'FEEDICO_SYNC_DELETE_DATA', true );
```

---

## Changelog (recent)

**1.7.8** — Time-sliced sync, `wp_feedico_sync_seen` table, transient lock with refresh  
**1.7.7** — Background sync queue, slim dashboard storage in options  
**1.7.0** — Gutenberg blocks, privacy hooks, CPT `noindex`, improved uninstall docs  

Full history: [`readme.txt`](readme.txt) (WordPress.org format).

---

## License

GPLv2 or later — see [`license.txt`](license.txt).

Questions or integration help: [feedico.io](https://feedico.io) · [Documentation hub](https://feedico.io/docs)
