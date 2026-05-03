# Feedico Sync — How to Use (Complete Guide)

This guide explains **Feedico Sync** end to end: installation, admin configuration, scheduled and manual sync, merchants and coupons, front-end shortcodes and blocks, WordPress post types, privacy, uninstall, and troubleshooting. It targets plugin release family **1.7.x**. Some labels in the WordPress admin may still follow your site language pack.

---

## Table of contents

1. [What is this plugin?](#1-what-is-this-plugin)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Admin menu layout](#4-admin-menu-layout)
5. [Main settings screen (Feedico Sync)](#5-main-settings-screen-feedico-sync)
6. [Connection](#6-connection)
7. [Networks to sync](#7-networks-to-sync)
8. [Dashboard (API) cards](#8-dashboard-api-cards)
9. [Sync schedule](#9-sync-schedule)
10. [Removing the plugin (notice)](#10-removing-the-plugin-notice)
11. [Showing merchants and coupons on the site](#11-showing-merchants-and-coupons-on-the-site)
12. [Manual sync and last run summary](#12-manual-sync-and-last-run-summary)
13. [Recent sync logs](#13-recent-sync-logs)
14. [Merchants list](#14-merchants-list)
15. [Merchant edit screen](#15-merchant-edit-screen)
16. [Create landing page](#16-create-landing-page)
17. [Coupons list](#17-coupons-list)
18. [Coupon edit screen](#18-coupon-edit-screen)
19. [Front end: shortcode reference](#19-front-end-shortcode-reference)
20. [Front end: Gutenberg blocks](#20-front-end-gutenberg-blocks)
21. [Front end: search and pagination URL parameters](#21-front-end-search-and-pagination-url-parameters)
22. [Appearance: CSS and JavaScript](#22-appearance-css-and-javascript)
23. [WordPress post types (CPT) and SEO](#23-wordpress-post-types-cpt-and-seo)
24. [Data model (database summary)](#24-data-model-database-summary)
25. [How credentials are stored (encryption)](#25-how-credentials-are-stored-encryption)
26. [Privacy: export and erase](#26-privacy-export-and-erase)
27. [Complete uninstall and data removal](#27-complete-uninstall-and-data-removal)
28. [Troubleshooting](#28-troubleshooting)
29. [Appendix A — Producing a PDF from this Markdown](#29-appendix-a--producing-a-pdf-from-this-markdown)
30. [Appendix B — Quick reference](#30-appendix-b--quick-reference)
31. [Appendix C — Admin AJAX and admin-post actions (developers)](#31-appendix-c--admin-ajax-and-admin-post-actions-developers)

---

## 1. What is this plugin?

**Feedico Sync** connects your WordPress site to the Feedico API:

- Stores merchant and coupon data in **custom database tables**.
- Optionally mirrors rows as **WordPress post types** (`feedico_store`, `feedico_coupon`).
- Renders visitor-facing grids and lists via **shortcodes** and **Gutenberg blocks**.
- Runs **scheduled or manual** full sync jobs.

Example API base (see `Feedico_API` in code): `https://api.feedico.io/api/v1/…`

---

## 2. Requirements

| Component | Minimum |
|-----------|---------|
| WordPress | **6.0+** (see plugin header / `readme.txt`) |
| PHP | **7.4+** |
| PHP extension | **OpenSSL** recommended for strong encryption (a fallback path exists if OpenSSL is missing) |
| Capability | Typically **`manage_options`** to manage plugin settings |

---

## 3. Installation

1. Copy the plugin folder to **`wp-content/plugins/feedico-sync/`** or upload the ZIP under **Plugins → Add New**.
2. **Activate** **Feedico Sync** on the **Plugins** screen.
3. On activation the plugin typically:
   - Creates or upgrades **custom tables**.
   - Registers **post types**.
   - Participates in **rewrite flush** (`flush_rewrite_rules`) on activation.

Open **Feedico Sync** from the admin menu for first-time setup.

---

## 4. Admin menu layout

Under **Feedico Sync** in the left menu you will usually see:

| Menu item | Purpose |
|-----------|---------|
| **Feedico Sync** | Main settings: connection, networks, schedule, logs |
| **Merchants** | Full merchant data grid |
| **Coupons** | Full coupon data grid |

Screens reached **only by URL** (no submenu label):

- `admin.php?page=feedico-sync-merchant-edit&merchant_id=…` — single merchant editor  
- `admin.php?page=feedico-sync-coupon-edit&coupon_id=…` — single coupon editor  

---

## 5. Main settings screen (Feedico Sync)

On `wp-admin/admin.php?page=feedico-sync` you will find:

1. **Hero header** — shortcuts to Merchants and Coupons  
2. **Last sync summary** — colored banner (success or error)  
3. **Run sync now** — starts a full sync via AJAX  
4. **Connection + network picker + API dashboard** — two-column layout  
5. **Sync schedule** — WP-Cron interval plus **last sync duration** side panel  
6. **Removing the plugin** — explains default uninstall behavior  
7. **Show merchants & coupons on the site** — shortcode / block cheat sheet  
8. **Save settings** — submits the settings form  
9. **Recent sync logs** — full-width table below the form  

If saved credentials exist, **Dashboard (API)** cards may **refresh in the background** after page load so numbers stay current without clicking **Test connection**.

---

## 6. Connection

Fields:

| Field | Description |
|-------|-------------|
| **Email** | Feedico account email |
| **Password** | Optional; leave blank to keep the password already stored on the server |
| **API token** | Optional; leave blank to keep the stored token |

**Test connection**:

- Calls AJAX action `feedico_sync_test`.
- On success: updates connection flag, last dashboard payload, network catalog; then reloads the page.

**Save settings**: email is always updated; password and token are re-saved **only** when the fields are non-empty (encrypted before storage).

---

## 7. Networks to sync

- After a successful connection test, **affiliate networks** appear as checkboxes.
- Checked networks drive **provider filters** during sync.
- The list comes from the dashboard-derived catalog; a background refresh tries to **preserve** checkbox state in the browser when possible.

**Save settings** writes the selection to option `feedico_sync_selected_networks`.

---

## 8. Dashboard (API) cards

- Shows summary information for networks linked to your Feedico account.
- Data is cached in option **`feedico_sync_last_dashboard`**; **Test connection** and (when enabled) **page-load refresh** update it.
- These cards are **not** the same as **local sync statistics** (those come from the sync job and log tables).

---

## 9. Sync schedule

### Interval

Pick one recurrence registered with WordPress `cron_schedules`:

- Minutes: **5, 10, 15, 30, 45**  
- Hours: **hourly**, **2 / 4 / 6 / 12** hours  
- Core schedules: **twicedaily**, **daily**  
- **Once weekly**  
- **Custom interval (minutes)** between **5** and **10080** (7 days)  

When **Custom** is selected, the **Minutes between runs** field appears.

### Last sync duration

- Shows how long the last full sync **run** took on the server (for example `3 min 12 sec`).
- The note recommends choosing an interval **comfortably longer** than this duration so overlapping WP-Cron triggers are less likely. Actual timing still depends on **traffic** and **hosting**.

### Next WP-Cron

The screen shows the next scheduled run time. WP-Cron is **not** a system crontab; for wall-clock accuracy use a real server cron hitting `wp-cron.php` (see WordPress documentation).

---

## 10. Removing the plugin (notice)

**Default behavior**: uninstalling the plugin **does not** drop custom tables or mirrored posts, so you can reinstall without losing data.

For a **full wipe**, add the following to `wp-config.php` **before** uninstalling:

```php
define( 'FEEDICO_SYNC_DELETE_DATA', true );
```

See [Complete uninstall and data removal](#27-complete-uninstall-and-data-removal).

---

## 11. Showing merchants and coupons on the site

The settings card summarizes:

- Blocks: `feedico/merchants`, `feedico/coupons`, `feedico/merchant-page`  
- Shortcodes: `[feedico_merchants]`, `[feedico_coupons]`, `[feedico_merchant_page]`  

Visitors only see records considered **active** after sync (`wp_feedico_active` and passive-marking rules).

Front-end stylesheet: `wp-content/plugins/feedico-sync/assets/public.css`

---

## 12. Manual sync and last run summary

- **Run sync now** runs `Feedico_Sync_Job::run( 'manual' )`.  
- **Prerequisite**: stored **email** and **API token** must be present; if the token is missing the job stops with an error such as “Email and API token are required.”
- Status text updates in place; response may include fresh **banner** HTML.
- Last run metadata is stored in option `feedico_sync_last_run` (finish time, trigger, success flag, stats, **duration**, etc.).

Scheduled runs use hook `feedico_sync_cron` and call `run( 'cron' )`.

---

## 13. Recent sync logs

Table columns (database `{prefix}feedico_sync_log`):

| Column | Meaning |
|--------|---------|
| ID | Log row id |
| Started / Finished | Timestamps |
| Status | For example success or error |
| Trigger | `cron` or `manual` |
| Stats / error | JSON stats summary or error text |

---

## 14. Merchants list

**Feedico Sync → Merchants**

- 30 rows per page, sortable columns, search box.
- Columns (labels may be translated): Name, Description, ID, Provider, Coupons (active), Website, Status, Active, Last sync.

Row actions (capability-dependent):

- **Edit** — full-page merchant editor  
- **Create landing page** — creates a draft **Page** containing the merchant shortcode (see below)  

---

## 15. Merchant edit screen

**URL:** `admin.php?page=feedico-sync-merchant-edit&merchant_id={PRIMARY_KEY}`

Read-only reference:

- Record ID, Provider, Property ID, External merchant key, Last synced (API)

Editable fields:

| Field | Description |
|-------|-------------|
| **Display name** | Public label |
| **Description** | Long text |
| **Website URL** | `example.com` or full `https://…` |
| **Status** | Status string from the API |
| **Active in WordPress** | Uncheck to hide the merchant and related presentation without deleting the row |

**Manual edit lock**

- Saving the form typically **sets** the lock so future syncs **preserve** your WordPress edits (implementation in `Feedico_DB` / sync job).
- Checkbox **On save, clear the lock…** removes the lock so the **next sync** can overwrite these fields from the API again.

---

## 16. Create landing page

From the merchants list, **Create landing page**:

- Uses `admin-post.php` action `feedico_create_merchant_landing` (nonce protected).
- Inserts a **draft Page** with:
  - Title pattern `{Merchant name} — Coupons` (translatable string)  
  - Intro paragraph plus block markup for **`[feedico_merchant_page merchant_id="…"]`**  
- Redirects you to the WordPress **page editor**.

Capabilities: `manage_options` and **`edit_pages`**.

---

## 17. Coupons list

**Feedico Sync → Coupons**

- **30** rows per page, sortable columns (default ordering is often `wp_row_updated_at` descending), search box.

**Columns:**

| Column | Summary |
|--------|---------|
| Title | Title plus coupon **ID** on a second line |
| Network | Network name or shortened `network_id` |
| Merchant ID | Linked merchant primary key |
| Code | Coupon code |
| Discount | Discount type + value + optional currency |
| Ends | End time |
| Active | Yes / No (`wp_feedico_active`) |
| Offer link | External **Open** link |

Row action: **Edit** opens the full coupon editor.

---

## 18. Coupon edit screen

**URL:** `admin.php?page=feedico-sync-coupon-edit&coupon_id={PRIMARY_KEY}`

Reference:

- Coupon ID, Merchant ID, Network ID, Created / updated (API)

Editable fields:

| Field | Description |
|-------|-------------|
| Title | Offer title |
| Description | Long text |
| Coupon code | Code string |
| Affiliate / offer URL | Click-through URL |
| Image URL | Creative image URL |
| Network name | Display network label |
| Starts at / Ends at | Usually API-style ISO 8601 strings |
| Discount type / Discount value | Discount metadata |
| Currency code | Currency |
| Status | Status string |
| **Active in WordPress** | Whether the coupon appears on the front end |

**Manual edit lock:** same model as merchants; use the checkbox to allow the next sync to overwrite from the API.

---

## 19. Front end: shortcode reference

Shortcodes are registered in `Feedico_Public`. Public CSS and JS load the first time a shortcode renders.

### `[feedico_merchants]`

| Attribute | Default | Range / notes |
|-----------|---------|----------------|
| `per_page` | `24` | 1–100 |
| `page` | `1` | Query var `fcm_p` usually wins |

Features:

- Search query: GET `fcm_q`  
- Pagination: GET `fcm_p`  
- **Coupons** link on each merchant card adds `fcc_mid` to the current page URL  

### `[feedico_coupons]`

| Attribute | Default | Notes |
|-----------|---------|-------|
| `per_page` | `24` | 1–100 |
| `page` | `1` | Overridable by `fcc_p` |
| `merchant_id` | empty | Restrict to one merchant |
| `wrapper` | `1` | `0`, `no`, `false`, `off` drop the outer wrapper |
| `search_form` | `0` | Set `1` to show search |

GET parameters: `fcc_q`, `fcc_p`, `fcc_mid` (URL `fcc_mid` applies when the shortcode omits `merchant_id`).

### `[feedico_merchant_page]`

| Attribute | Default | Notes |
|-----------|---------|-------|
| `merchant_id` | — | **Required** merchant primary key / ref |
| `per_page` | `24` | 1–100 |
| `search_form` | `0` | |
| `show_hero` | `1` | `0` hides the hero header block |

Internally renders `[feedico_coupons]` with `wrapper=0` and the same `merchant_id`.

---

## 20. Front end: Gutenberg blocks

| Block name | Maps to shortcode behavior |
|------------|----------------------------|
| `feedico/merchants` | `perPage` (1–100) |
| `feedico/coupons` | `merchantId`, `perPage`, `showSearch`, `wrapOuter` |
| `feedico/merchant-page` | `merchantId`, `perPage`, `showSearch`, `showHero` |

If **merchant-page** has an empty ID, the block may show an editor-only reminder to set the ID in the sidebar.

---

## 21. Front end: search and pagination URL parameters

| Parameter | Shortcode context | Meaning |
|-----------|-------------------|---------|
| `fcm_q` | merchants | Search text |
| `fcm_p` | merchants | Page number |
| `fcc_q` | coupons | Search text |
| `fcc_p` | coupons | Page number |
| `fcc_mid` | coupons | Merchant filter |

---

## 22. Appearance: CSS and JavaScript

- **`assets/public.css`** — front-end layout under `.feedico-pub`  
- **`assets/public.js`** — for example copy-button strings via `feedicoPub` localization  

Override in a child theme or **Appearance → Customize → Additional CSS** if a theme clashes.

---

## 23. WordPress post types (CPT) and SEO

The sync pipeline can create or update **WordPress posts** bound to custom table rows.

### Admin menus and URLs

Separate admin menu entries:

- **Feedico Stores** (`feedico_store`) — rewrite base **`/feedico-store/`** (single and archive URLs follow WordPress rewrite rules)  
- **Feedico Coupons** (`feedico_coupon`) — **`/feedico-coupon/`**

Both support **title** and **content** editors and expose **REST** (`show_in_rest`).

### Store post (`feedico_store`)

- Hidden meta **`_feedico_entity_id`** links to the merchants table primary key.
- On save, title and plain-text content may flow back through `Feedico_DB::update_merchant_from_cpt` (guarded against sync loops).

### Coupon post (`feedico_coupon`)

- Also uses **`_feedico_entity_id`**.
- **Coupon details** meta box fields:
  - **Coupon code** → `feedico_coupon_code`  
  - **Affiliate URL** → `feedico_affiliate_url`  
  - **Expiry (end date / ISO)** → `feedico_expires_at`  
- Creating a brand-new coupon **only** from this screen does **not** insert a Feedico table row; the authoritative source is **Feedico Sync admin + API sync**. Editing an existing mirrored coupon can update the table row.

### SEO

`Feedico_Seo` adds **`noindex`** to singles and archives for these post types by default. Allow indexing with:

```php
add_filter( 'feedico_sync_cpt_noindex', '__return_false' );
```

You are responsible for SEO and legal compliance when enabling indexing.

---

## 24. Data model (database summary)

With WordPress table prefix `{prefix}`:

| Table | Purpose |
|-------|---------|
| `{prefix}feedico_merchants` | Merchant rows + JSON payload |
| `{prefix}feedico_coupons` | Coupon rows + JSON payload |
| `{prefix}feedico_sync_log` | Sync run logs |

Important flags: `wp_feedico_active`, `wp_manual_override`, `wp_deactivated_at`.

---

## 25. How credentials are stored (encryption)

`Feedico_Crypto`:

- With OpenSSL: **AES-256-CBC** using a key derived from WordPress **salts**.
- Without OpenSSL: weak legacy fallback — enable OpenSSL in production.

Option keys include: `feedico_sync_email`, `feedico_sync_password_enc`, `feedico_sync_token_enc`.

---

## 26. Privacy: export and erase

Under **Tools → Export Personal Data** and **Erase Personal Data**:

- **Export**: if the request email matches the stored Feedico email, the export bundle includes that fact.
- **Erase**: if it matches, plugin options are deleted and `feedico_sync_cron` is unscheduled; **custom tables are not dropped** (see `readme.txt`).

---

## 27. Complete uninstall and data removal

1. **Default**: deleting the plugin leaves tables and CPT posts.  
2. **Full removal**: before deleting the plugin, set in `wp-config.php`:

```php
define( 'FEEDICO_SYNC_DELETE_DATA', true );
```

Then `uninstall.php`:

- Drops custom tables  
- Deletes `feedico_store` and `feedico_coupon` posts  
- Deletes known options  
- Clears the scheduled cron hook  

---

## 28. Troubleshooting

| Symptom | Likely cause / fix |
|---------|---------------------|
| Dashboard empty or stale | Run **Test connection**; verify email + token |
| Sync says no networks | Check network checkboxes, **Save settings** |
| WP-Cron never fires | Low-traffic sites: use a real server cron |
| Edits overwritten after sync | Check **manual lock**; use “clear lock” only when you want API data |
| No coupons on front end | `wp_feedico_active`, passive rules, last successful sync |
| Encryption issues | Enable PHP **openssl** extension |

---

## 29. Appendix A — Producing a PDF from this Markdown

This guide ships as **Markdown** (`.md`). Common PDF workflows:

### 1) Pandoc (recommended on desktop)

[https://pandoc.org](https://pandoc.org)

Example (adjust the path to your site):

```bash
cd /path/to/wp-content/plugins/feedico-sync/docs
pandoc HOW-TO.md -o Feedico-Sync-How-To.pdf --pdf-engine=xelatex -V lang=en
```

Without `xelatex`:

```bash
pandoc HOW-TO.md -o Feedico-Sync-How-To.pdf --pdf-engine=wkhtmltopdf
```

### 2) Browser print to PDF

1. Open a rendered preview (VS Code Markdown preview, GitHub, etc.).  
2. **Ctrl+P** / **Cmd+P** → **Save as PDF** / **Microsoft Print to PDF**.

### 3) VS Code extensions

Extensions such as “Markdown PDF” can export `.md` directly to `.pdf`.

> **Note:** For complex tables, Pandoc with LaTeX or `wkhtmltopdf` usually yields the cleanest layout.

---

## 30. Appendix B — Quick reference

| What | Where |
|------|-------|
| Main settings | `wp-admin/admin.php?page=feedico-sync` |
| Merchants grid | `…page=feedico-sync-merchants` |
| Coupons grid | `…page=feedico-sync-coupons` |
| Merchant editor | `…page=feedico-sync-merchant-edit&merchant_id=…` |
| Coupon editor | `…page=feedico-sync-coupon-edit&coupon_id=…` |

Example shortcodes:

```text
[feedico_merchants per_page="24"]
[feedico_coupons per_page="24" merchant_id="PRIMARY_KEY"]
[feedico_merchant_page merchant_id="PRIMARY_KEY" per_page="24" show_hero="1" search_form="0"]
```

---

## 31. Appendix C — Admin AJAX and admin-post actions (developers)

### AJAX (`admin-ajax.php`)

All require `manage_options` plus nonce `feedico_sync_ajax`.

| `action` | Role |
|----------|------|
| `feedico_sync_test` | POST `email`, `password`, `token` — connection test + dashboard + catalog |
| `feedico_sync_run` | Manual full sync; response includes `banner_html`, `last_sync_duration`, etc. |
| `feedico_sync_refresh_dashboard` | Refresh dashboard from **stored** credentials (main settings screen) |

### `admin-post.php`

| `action` | Role |
|----------|------|
| `feedico_save_merchant` | Merchant editor form save |
| `feedico_save_coupon` | Coupon editor form save |
| `feedico_create_merchant_landing` | GET + nonce — draft landing page |

---

*This document is aligned with the `feedico-sync` source tree (`includes/`, `assets/`, `feedico-sync.php`, `uninstall.php`, `readme.txt`). When the product changes, treat the code as the source of truth.*
