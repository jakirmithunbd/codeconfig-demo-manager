<?php
/**
 * Plugin Name:       CodeConfig Demo Manager
 * Plugin URI:        https://codeconfig.dev
 * Description:       Cross-domain demo system. Runs in two modes: "form" on codeconfig.dev captures leads and calls the demo site API; "demo" on each demo subdomain serves the REST API, creates temp users, and auto-expires them.
 * Version:           2.1.0
 * Author:            CodeConfig
 * Author URI:        https://codeconfig.dev
 * Text Domain:       codeconfig-demo
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

// ── PHP version gate ────────────────────────────────────────────────────────
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>CodeConfig Demo Manager</strong> requires PHP 7.4 or higher. You are running PHP ' . PHP_VERSION . '.</p></div>';
    } );
    return;
}

define( 'CCDEMO_VERSION', '2.1.0' );
define( 'CCDEMO_FILE',    __FILE__ );
define( 'CCDEMO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'CCDEMO_URL',     plugin_dir_url( __FILE__ ) );
define( 'CCDEMO_TABLE',   'cc_demo_sessions' );

/**
 * Returns the configured operating mode.
 *   'form' → codeconfig.dev  (captures leads, calls remote API)
 *   'demo' → demo subdomain (REST server, creates temp users, auth)
 */
function ccdemo_mode() {
    return get_option( 'ccdemo_mode', 'form' );
}

// Always-needed helpers (both modes)
require_once CCDEMO_PATH . 'includes/class-demo-products.php';
require_once CCDEMO_PATH . 'includes/class-demo-db.php';
require_once CCDEMO_PATH . 'includes/class-demo-email.php';
require_once CCDEMO_PATH . 'includes/class-demo-admin.php';

if ( ccdemo_mode() === 'demo' ) {
    // ── demo subdomain (igd.codeconfig.dev, idb.codeconfig.dev …) ──────────
    require_once CCDEMO_PATH . 'includes/class-demo-api-server.php';
    require_once CCDEMO_PATH . 'includes/class-demo-auth.php';
    require_once CCDEMO_PATH . 'includes/class-demo-cron.php';

    register_activation_hook( __FILE__, array( 'CCDemo_DB',   'install' ) );
    register_activation_hook( __FILE__, array( 'CCDemo_Cron', 'schedule' ) );
    register_deactivation_hook( __FILE__, array( 'CCDemo_Cron', 'unschedule' ) );

    add_action( 'plugins_loaded', 'ccdemo_boot_demo_mode' );

} else {
    // ── codeconfig.dev (form / lead capture) ───────────────────────────────
    require_once CCDEMO_PATH . 'includes/class-demo-api-client.php';
    require_once CCDEMO_PATH . 'includes/class-demo-form.php';

    add_action( 'plugins_loaded', 'ccdemo_boot_form_mode' );
}

/**
 * Boot demo-site mode.
 * Also ensures the DB table and cron job exist even when
 * the plugin was uploaded without going through the Activate button.
 */
function ccdemo_boot_demo_mode() {
    // Auto-create DB table + cron if the activation hook was never fired
    // (common when files are deployed via Git, FTP, or zip-upload).
    if ( get_option( 'ccdemo_db_version' ) !== CCDEMO_VERSION ) {
        CCDemo_DB::install();
        CCDemo_Cron::schedule();
    }

    new CCDemo_API_Server();
    new CCDemo_Auth();
    new CCDemo_Admin();
}

/**
 * Boot form-site mode.
 */
function ccdemo_boot_form_mode() {
    new CCDemo_Form();
    new CCDemo_Admin();
}
