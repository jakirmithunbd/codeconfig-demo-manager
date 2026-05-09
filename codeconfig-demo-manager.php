<?php
/**
 * Plugin Name:       CodeConfig Demo Manager
 * Plugin URI:        https://codeconfig.dev
 * Description:       Cross-domain demo system. Runs in two modes: "form" on codeconfig.dev captures leads and calls the demo site API; "demo" on demo.codeconfig.dev serves the REST API, creates temp users, and auto-expires them.
 * Version:           2.0.0
 * Author:            CodeConfig
 * Author URI:        https://codeconfig.dev
 * Text Domain:       codeconfig-demo
 * Requires at least: 6.3
 * Requires PHP:      8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'CCDEMO_VERSION', '2.0.0' );
define( 'CCDEMO_FILE',    __FILE__ );
define( 'CCDEMO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'CCDEMO_URL',     plugin_dir_url( __FILE__ ) );
define( 'CCDEMO_TABLE',   'cc_demo_sessions' );

/**
 * Returns the configured operating mode.
 *   'form' → this is codeconfig.dev  (captures leads, calls remote API)
 *   'demo' → this is demo.codeconfig.dev (REST server, creates users, auth)
 */
function ccdemo_mode(): string {
    return get_option( 'ccdemo_mode', 'form' );
}

// Always-needed helpers
require_once CCDEMO_PATH . 'includes/class-demo-db.php';
require_once CCDEMO_PATH . 'includes/class-demo-email.php';
require_once CCDEMO_PATH . 'includes/class-demo-admin.php';

if ( ccdemo_mode() === 'demo' ) {
    // ── demo.codeconfig.dev ──────────────────────────────────────────
    require_once CCDEMO_PATH . 'includes/class-demo-api-server.php';
    require_once CCDEMO_PATH . 'includes/class-demo-auth.php';
    require_once CCDEMO_PATH . 'includes/class-demo-cron.php';

    register_activation_hook(   __FILE__, [ 'CCDemo_DB',    'install' ] );
    register_activation_hook(   __FILE__, [ 'CCDemo_Cron',  'schedule' ] );
    register_deactivation_hook( __FILE__, [ 'CCDemo_Cron',  'unschedule' ] );

    add_action( 'plugins_loaded', static function () {
        new CCDemo_API_Server();
        new CCDemo_Auth();
        new CCDemo_Admin();
    } );

} else {
    // ── codeconfig.dev (default) ─────────────────────────────────────
    require_once CCDEMO_PATH . 'includes/class-demo-api-client.php';
    require_once CCDEMO_PATH . 'includes/class-demo-form.php';

    add_action( 'plugins_loaded', static function () {
        new CCDemo_Form();
        new CCDemo_Admin();
    } );
}
