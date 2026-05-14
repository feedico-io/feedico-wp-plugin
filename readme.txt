=== Feedico Sync ===
Contributors: feedico
Tags: coupons, merchants, affiliate, sync, feedico
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize Feedico merchants and coupons into WordPress, mirror them as public post types, and display grids on the front end with blocks or shortcodes.

== Description ==

Feedico Sync connects your site to the Feedico API, stores directory data in custom tables, keeps WordPress posts in sync, and exposes optional blocks plus shortcodes for visitors.

* Scheduled or manual sync
* Admin grids for merchants and coupons
* Front-end blocks: `feedico/merchants`, `feedico/coupons`, `feedico/merchant-page`
* Shortcodes: `[feedico_merchants]`, `[feedico_coupons]`, `[feedico_merchant_page]`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install through the Plugins screen.
2. Activate the plugin.
3. Open **Feedico Sync** in the admin menu and configure credentials, schedule, and networks.

== Frequently Asked Questions ==

= How do I remove all plugin data on uninstall? =

By default, uninstall keeps custom tables and `feedico_store` / `feedico_coupon` posts. To delete tables, those posts, and plugin options when deleting the plugin, add to `wp-config.php` *before* uninstalling:

`define( 'FEEDICO_SYNC_DELETE_DATA', true );`

= Does the plugin support privacy export/erase? =

Yes. Under **Tools → Export personal data** and **Erase personal data**, the Feedico exporter includes the saved Feedico account email; the eraser clears stored credentials and related options (it does not drop custom tables).

= Can I turn off `noindex` on Feedico post types? =

Yes. Use the filter `feedico_sync_cpt_noindex` and return `false` to allow search engines to index store/coupon URLs.

== Changelog ==

= 1.7.7 =
* Store only a slim dashboard snapshot in options so oversized API payloads no longer bloat `wp_options` or freeze the settings screen; one-time prune for legacy saves.
* When selected networks change on Save settings, queue a full sync via WP-Cron (`feedico_sync_background`) instead of blocking the admin request; `spawn_cron()` nudges timely runs when the host allows it.
* “Run sync now” also queues the same background job and returns immediately (log and banner update after the job finishes).
* Clear the background hook on deactivate/uninstall alongside the recurring cron hook.

= 1.7.0 =
* Uninstall option to remove CPT posts when `FEEDICO_SYNC_DELETE_DATA` is true.
* Privacy API exporter/eraser for stored settings.
* Gutenberg dynamic blocks mirroring shortcodes.
* `noindex` on Feedico CPT singles and archives (filterable).
* Merchant/coupon admin: optional “clear manual lock” on save for API overwrite on next sync.
* `readme.txt`, `license.txt`, and docs for delete-data behavior.

== Upgrade Notice ==

= 1.7.7 =
Background sync queue and smaller stored dashboard data. Ensure WP-Cron or a real server cron hits `wp-cron.php` if you rely on timely syncs on low-traffic sites.

= 1.7.0 =
Blocks, privacy hooks, SEO defaults for CPTs, and improved uninstall/documentation.
