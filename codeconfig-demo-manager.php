<?php
/**
 * Plugin Name:       CodeConfig Demo Manager
 * Plugin URI:        https://codeconfig.com
 * Description:       Lead-capture demo system: user selects product, gets a magic-link email, logs into a time-limited WP dashboard, then the account auto-deletes after 1–2 days.
 * Version:           1.0.0
 * Author:            CodeConfig
 * Author URI:        https://codeconfig.com
 * Text Domain:       codeconfig-demo
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CCDEMO_VERSION',     '1.0.0' );
define( 'CCDEMO_FILE',        __FILE__ );
define( 'CCDEMO_PATH',        plugin_dir_path( __FILE__ ) );
define( 'CCDEMO_URL',         plugin_dir_url( __FILE__ ) );
define( 'CCDEMO_TABLE',       'cc_demo_sessions' );

require_once CCDEMO_PATH . 'includes/class-demo-db.php';
require_once CCDEMO_PATH . 'includes/class-demo-email.php';
require_once CCDEMO_PATH . 'includes/class-demo-form.php';
require_once CCDEMO_PATH . 'includes/class-demo-auth.php';
require_once CCDEMO_PATH . 'includes/class-demo-cron.php';
require_once CCDEMO_PATH . 'includes/class-demo-admin.php';

register_activation_hook(   __FILE__, [ 'CCDemo_DB',    'install' ] );
register_activation_hook(   __FILE__, [ 'CCDemo_Cron',  'schedule' ] );
register_deactivation_hook( __FILE__, [ 'CCDemo_Cron',  'unschedule' ] );

add_action( 'plugins_loaded', function () {
    new CCDemo_Form();
    new CCDemo_Auth();
    new CCDemo_Admin();
} );
