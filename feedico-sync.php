<?php
/**
 * Plugin Name:       Feedico Sync
 * Description:       Connects to Feedico API, syncs merchants and coupons to the WP database, and shows them on the site via shortcodes.
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Feedico
 * License:           GPL v2 or later
 * Text Domain:       feedico-sync
 *
 * @package Feedico_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FEEDICO_SYNC_VERSION', '1.7.6' );
define( 'FEEDICO_SYNC_FILE', __FILE__ );
define( 'FEEDICO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'FEEDICO_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-crypto.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-api.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-db.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-post-types.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-sync-job.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-public.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-privacy.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-blocks.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-seo.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-admin.php';
require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-sync.php';

register_activation_hook( __FILE__, array( 'Feedico_Sync_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Feedico_Sync_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Feedico_Sync_Plugin', 'init' ) );
