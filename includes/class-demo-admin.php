<?php
/**
 * Admin panel — works in both modes.
 *
 * form mode  → Settings (products + API), per-site health dashboard, shortcode ref
 * demo mode  → Settings + session list + manual cleanup
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_init',            [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ $this, 'action_notices' ] );

        // Bust product cache when the raw list is saved
        add_action( 'update_option_ccdemo_products_raw', static function () {
            CCDemo_Products::bust_cache();
        } );
    }

    /* ------------------------------------------------------------------
     * Menu
     * ------------------------------------------------------------------ */

    public function add_menu(): void {
        $main_cb = ccdemo_mode() === 'demo' ? [ $this, 'page_sessions' ] : [ $this, 'page_overview' ];

        add_menu_page( 'Demo Manager', 'Demo Manager', 'manage_options',
            'ccdemo-manager', $main_cb, 'dashicons-welcome-view-site', 30 );

        if ( ccdemo_mode() === 'demo' ) {
            add_submenu_page( 'ccdemo-manager', 'Sessions', 'Sessions',
                'manage_options', 'ccdemo-manager', [ $this, 'page_sessions' ] );
        }

        add_submenu_page( 'ccdemo-manager', 'Settings', 'Settings',
            'manage_options', 'ccdemo-settings', [ $this, 'page_settings' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_ccdemo-manager', 'demo-manager_page_ccdemo-settings' ], true ) ) {
            return;
        }
        wp_enqueue_style( 'ccdemo-admin', CCDEMO_URL . 'assets/css/admin.css', [], CCDEMO_VERSION );
    }

    /* ------------------------------------------------------------------
     * Form-site overview page
     * ------------------------------------------------------------------ */

    public function page_overview(): void {
        $statuses = CCDemo_API_Client::check_all_sites();
        ?>
        <div class="wrap ccdemo-admin-wrap">
            <h1>Demo Manager <span class="ccdemo-mode-badge badge-form">Form Site — codeconfig.dev</span></h1>

            <h2 style="margin-top:24px;">Demo Sites Status</h2>
            <p style="color:#6b7280;margin-bottom:12px;">
                Each product routes to its own demo subdomain. Status is cached for 5 minutes.
                <a href="<?php echo esc_url( add_query_arg( 'ccdemo_flush_health', 1 ) ); ?>">Refresh now</a>
            </p>

            <?php if ( empty( $statuses ) ) : ?>
                <p>No products configured. Go to <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccdemo-settings' ) ); ?>">Settings</a> to add products.</p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Demo Subdomain</th>
                        <th>Status</th>
                        <th>Plugin Version</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $statuses as $slug => $s ) :
                    $product = CCDemo_Products::get( $slug );
                    $url     = $product['demo_url'] ?? '';
                    $has_own_key = ! empty( $product['api_key'] );
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $s['label'] ); ?></strong>
                            <?php if ( $has_own_key ) : ?>
                                <span class="ccdemo-badge badge-pending" title="Uses product-specific API key">Own Key</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $url ) : ?>
                                <a href="<?php echo esc_url( $url ); ?>" target="_blank">
                                    <?php echo esc_html( $url ); ?>
                                </a>
                            <?php else : ?>
                                <em style="color:#9ca3af;">Not configured</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! $url ) : ?>
                                <span class="ccdemo-badge badge-expired">Missing URL</span>
                            <?php elseif ( $s['ok'] ?? false ) : ?>
                                <span class="ccdemo-badge badge-active">&#10003; Online</span>
                            <?php else : ?>
                                <span class="ccdemo-badge badge-expired" title="<?php echo esc_attr( $s['error'] ?? '' ); ?>">
                                    &#10007; Offline
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $s['version'] ?? '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php $this->shortcode_reference(); ?>
            <?php $this->how_it_works(); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Demo-site sessions page
     * ------------------------------------------------------------------ */

    public function page_sessions(): void {
        $sessions = CCDemo_DB::get_all_sessions( 500 );
        $products = CCDemo_Products::labels();
        $now      = current_time( 'timestamp' );

        $total   = count( $sessions );
        $active  = count( array_filter( $sessions, fn( $s ) => $s->status === 'active' ) );
        $pending = count( array_filter( $sessions, fn( $s ) => $s->status === 'pending' ) );
        $expired = count( array_filter( $sessions, fn( $s ) => $s->status === 'expired' ) );
        ?>
        <div class="wrap ccdemo-admin-wrap">
            <h1 class="wp-heading-inline">
                Demo Sessions
                <span class="ccdemo-mode-badge badge-demo">
                    <?php echo esc_html( home_url() ); ?>
                </span>
            </h1>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ccdemo-manager&action=cleanup' ), 'ccdemo_cleanup' ) ); ?>"
               class="page-title-action">&#9851; Run Cleanup</a>
            <hr class="wp-header-end">

            <div class="ccdemo-stats">
                <div class="ccdemo-stat"><span><?php echo $total; ?></span>Total</div>
                <div class="ccdemo-stat stat-active"><span><?php echo $active; ?></span>Active</div>
                <div class="ccdemo-stat stat-pending"><span><?php echo $pending; ?></span>Pending</div>
                <div class="ccdemo-stat stat-expired"><span><?php echo $expired; ?></span>Expired</div>
            </div>

            <table class="wp-list-table widefat fixed striped ccdemo-sessions-table">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Name / Email</th>
                        <th>Company</th>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $sessions ) ) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">No demo sessions yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $sessions as $s ) :
                        $expires_ts = strtotime( $s->expires_at );
                        $is_expired = $expires_ts < $now || $s->status === 'expired';
                        $badges     = [
                            'active'  => '<span class="ccdemo-badge badge-active">Active</span>',
                            'pending' => '<span class="ccdemo-badge badge-pending">Pending</span>',
                            'expired' => '<span class="ccdemo-badge badge-expired">Expired</span>',
                        ];
                        $product_label = $products[ $s->product ] ?? $s->product;
                        $delete_url    = wp_nonce_url( admin_url( "admin.php?page=ccdemo-manager&action=delete&session_id={$s->id}" ), 'ccdemo_delete_' . $s->id );
                        $extend_url    = wp_nonce_url( admin_url( "admin.php?page=ccdemo-manager&action=extend&session_id={$s->id}" ), 'ccdemo_extend_' . $s->id );
                    ?>
                    <tr class="<?php echo $is_expired ? 'ccdemo-row-expired' : ''; ?>">
                        <td><?php echo (int) $s->id; ?></td>
                        <td>
                            <strong><?php echo esc_html( $s->name ); ?></strong><br>
                            <a href="mailto:<?php echo esc_attr( $s->email ); ?>"><?php echo esc_html( $s->email ); ?></a>
                            <?php if ( $s->phone ) : ?><br><small><?php echo esc_html( $s->phone ); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $s->company ?: '—' ); ?></td>
                        <td><?php echo esc_html( $product_label ); ?></td>
                        <td><?php echo $badges[ $s->status ] ?? esc_html( $s->status ); ?></td>
                        <td><small><?php echo esc_html( date_i18n( 'M j Y H:i', strtotime( $s->created_at ) ) ); ?></small></td>
                        <td>
                            <small><?php echo esc_html( date_i18n( 'M j Y H:i', $expires_ts ) ); ?></small>
                            <?php if ( ! $is_expired ) :
                                $left = $expires_ts - $now;
                                echo '<br><small style="color:#16a34a;">';
                                echo $left > DAY_IN_SECONDS
                                    ? round( $left / DAY_IN_SECONDS ) . 'd left'
                                    : round( $left / HOUR_IN_SECONDS ) . 'h left';
                                echo '</small>';
                            endif; ?>
                        </td>
                        <td>
                            <?php if ( ! $is_expired ) : ?>
                                <a href="<?php echo esc_url( $extend_url ); ?>" class="button button-small">+1 Day</a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $delete_url ); ?>"
                               class="button button-small button-link-delete"
                               onclick="return confirm('Delete this session and its demo user account?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Settings page
     * ------------------------------------------------------------------ */

    public function page_settings(): void {
        $is_demo = ccdemo_mode() === 'demo';
        ?>
        <div class="wrap ccdemo-admin-wrap">
            <h1>
                Demo Manager — Settings
                <span class="ccdemo-mode-badge <?php echo $is_demo ? 'badge-demo' : 'badge-form'; ?>">
                    <?php echo $is_demo ? 'Demo Site' : 'Form Site'; ?>
                </span>
            </h1>

            <?php if ( ! $is_demo ) : ?>
            <div class="notice notice-info inline" style="margin:10px 0 20px;">
                <p>
                    <strong>Two-site setup:</strong>
                    Install this plugin on every demo subdomain and set its mode to <strong>Demo</strong>.
                    All sites share the same <strong>API Key</strong> (or set a per-product key in the Products table below).
                </p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'ccdemo_settings_group' );
                do_settings_sections( 'ccdemo-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Register settings
     * ------------------------------------------------------------------ */

    public function register_settings(): void {
        $is_demo = ccdemo_mode() === 'demo';

        // ── Mode & API key ───────────────────────────────────────────
        add_settings_section( 'ccdemo_conn', '&#9881; Mode &amp; API', '__return_false', 'ccdemo-settings' );

        $this->field( 'ccdemo_mode', 'Site Mode', 'ccdemo_conn', static function () {
            $val = get_option( 'ccdemo_mode', 'form' );
            echo '<select name="ccdemo_mode">';
            foreach ( [ 'form' => 'Form Site (codeconfig.dev)', 'demo' => 'Demo Site (e.g. igd.codeconfig.dev)' ] as $k => $l ) {
                printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $l ) );
            }
            echo '</select>';
            echo '<p class="description">Save and reload after switching modes.</p>';
        }, 'sanitize_text_field' );

        $this->field( 'ccdemo_api_key', 'Global API Key', 'ccdemo_conn', static function () {
            $key = get_option( 'ccdemo_api_key', '' );
            $new = bin2hex( random_bytes( 32 ) );
            echo "<div style='display:flex;gap:8px;align-items:center;flex-wrap:wrap;'>";
            printf( "<input type='text' name='ccdemo_api_key' id='ccdemo_api_key' value='%s' style='width:440px;font-family:monospace;font-size:12px;' readonly>", esc_attr( $key ) );
            printf( "<button type='button' class='button' onclick=\"document.getElementById('ccdemo_api_key').removeAttribute('readonly');document.getElementById('ccdemo_api_key').value='%s';\">Generate New</button>", esc_js( $new ) );
            echo "</div>";
            echo "<p class='description'>Paste this same key on every demo subdomain. Individual products can override it in the Products table (column 4).</p>";
        }, 'sanitize_text_field' );

        // Demo-site extra: main site back-link URL
        if ( $is_demo ) {
            $this->field( 'ccdemo_main_site_url', 'Main Site URL', 'ccdemo_conn', static function () {
                $val = get_option( 'ccdemo_main_site_url', 'https://codeconfig.dev' );
                echo "<input type='url' name='ccdemo_main_site_url' value='" . esc_attr( $val ) . "' style='width:320px;' placeholder='https://codeconfig.dev'>";
                echo "<p class='description'>Used in demo expiry notices and error pages so users can return to request a new demo.</p>";
            }, 'esc_url_raw' );
        }

        // ── General ──────────────────────────────────────────────────
        add_settings_section( 'ccdemo_general', '&#9881; General', '__return_false', 'ccdemo-settings' );

        $this->field( 'ccdemo_expiry_days', 'Demo Duration (days)', 'ccdemo_general', static function () {
            $val = get_option( 'ccdemo_expiry_days', 2 );
            echo "<input type='number' name='ccdemo_expiry_days' value='" . esc_attr( $val ) . "' min='1' max='30' style='width:70px;'>";
            echo "<p class='description'>Accounts are auto-deleted this many days after creation.</p>";
        }, 'absint' );

        // ── Email (form site or demo site — both can send) ───────────
        add_settings_section( 'ccdemo_email_section', '&#9993; Email', '__return_false', 'ccdemo-settings' );

        foreach ( [
            [ 'ccdemo_from_name',     'From Name',        'text',  '',         'Sender name on demo invitation emails.' ],
            [ 'ccdemo_from_email',    'From Email',       'email', '',         'Sender address.' ],
            [ 'ccdemo_email_logo',    'Logo URL',         'url',   '',         'Logo shown at the top of the email.' ],
            [ 'ccdemo_primary_color', 'Brand Color (hex)','text',  '#4F46E5',  'Used in the email button and the form.' ],
        ] as [ $id, $label, $type, $default, $desc ] ) {
            $this->field( $id, $label, 'ccdemo_email_section', static function () use ( $id, $type, $default, $desc ) {
                $val = get_option( $id, $default );
                echo "<input type='{$type}' name='{$id}' value='" . esc_attr( $val ) . "' style='width:320px;'>";
                echo "<p class='description'>{$desc}</p>";
            }, 'sanitize_text_field' );
        }

        $this->field( 'ccdemo_admin_notify', 'Notify Admin', 'ccdemo_email_section', static function () {
            $val = get_option( 'ccdemo_admin_notify', 1 );
            echo "<label><input type='checkbox' name='ccdemo_admin_notify' value='1' " . checked( 1, (int) $val, false ) . "> Email admin on each new demo request</label>";
        }, 'absint' );

        // ── Products (form site only — this is where routing is set) ─
        if ( ! $is_demo ) {
            add_settings_section( 'ccdemo_products_section', '&#128230; Products &amp; Demo Subdomains', static function () {
                echo '<p>One product per line. Columns:</p>';
                echo '<code style="background:#f3f4f6;padding:6px 10px;border-radius:6px;display:inline-block;line-height:2;">';
                echo 'slug | Product Label | Demo Subdomain URL | (optional) API Key Override';
                echo '</code>';
                echo '<p style="color:#6b7280;font-size:13px;margin-top:6px;">Leave column 4 blank to use the Global API Key. Lines starting with # are ignored.</p>';
            }, 'ccdemo-settings' );

            register_setting( 'ccdemo_settings_group', 'ccdemo_products_raw', [
                'sanitize_callback' => [ $this, 'sanitize_products_raw' ],
            ] );

            add_settings_field( 'ccdemo_products_raw', 'Products', static function () {
                $stored = CCDemo_Products::all();
                $raw    = get_option( 'ccdemo_products_raw', CCDemo_Products::to_raw( $stored ) );

                echo "<textarea name='ccdemo_products_raw' rows='10' style='width:100%;max-width:700px;font-family:monospace;font-size:13px;line-height:1.7;'>" . esc_textarea( $raw ) . "</textarea>";
                echo "<p class='description' style='margin-top:6px;'>Example:<br>";
                echo "<code>google-drive|Integration for Google Drive|https://igd.codeconfig.dev</code><br>";
                echo "<code>dropbox|Integration for Dropbox|https://idb.codeconfig.dev</code></p>";
            }, 'ccdemo-settings', 'ccdemo_products_section' );
        }
    }

    /* ------------------------------------------------------------------
     * Helper: register field + option in one call
     * ------------------------------------------------------------------ */

    private function field( $option, $label, $section, $render, $sanitize ) {
        register_setting( 'ccdemo_settings_group', $option, array( 'sanitize_callback' => $sanitize ) );
        add_settings_field( $option, $label, $render, 'ccdemo-settings', $section );
    }

    /* ------------------------------------------------------------------
     * Products sanitiser
     * ------------------------------------------------------------------ */

    public function sanitize_products_raw( $raw ) {
        $products = CCDemo_Products::parse_raw( $raw );
        update_option( 'ccdemo_products_v2', $products );
        CCDemo_Products::bust_cache();
        return CCDemo_Products::to_raw( $products );
    }

    /* ------------------------------------------------------------------
     * Action handler (demo site)
     * ------------------------------------------------------------------ */

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Flush health check cache
        if ( isset( $_GET['ccdemo_flush_health'] ) ) {
            $products = CCDemo_Products::all();
            foreach ( array_keys( $products ) as $slug ) {
                delete_transient( 'ccdemo_health_' . md5( CCDemo_Products::demo_url_for( $slug ) . '/wp-json/ccdemo/v1' ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=ccdemo-manager' ) );
            exit;
        }

        $action     = sanitize_key( isset( $_GET['action'] )     ? $_GET['action']     : '' );
        $session_id = (int) ( isset( $_GET['session_id'] ) ? $_GET['session_id'] : 0 );

        if ( $action === 'delete' ) {
            check_admin_referer( 'ccdemo_delete_' . $session_id );
            CCDemo_Cron::delete_session_now( $session_id );
            $this->redirect_notice( 'deleted' );
        } elseif ( $action === 'extend' ) {
            check_admin_referer( 'ccdemo_extend_' . $session_id );
            $this->extend_session( $session_id );
            $this->redirect_notice( 'extended' );
        } elseif ( $action === 'cleanup' ) {
            check_admin_referer( 'ccdemo_cleanup' );
            $count = CCDemo_Cron::run_manual_cleanup();
            $this->redirect_notice( 'cleanup_' . $count );
        }
    }

    private function extend_session( $id, $days = 1 ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE id = %d",
            $id
        ) );
        if ( ! $row ) { return; }

        $new_expiry = gmdate( 'Y-m-d H:i:s', strtotime( $row->expires_at ) + $days * DAY_IN_SECONDS );
        CCDemo_DB::update_session( $id, [ 'expires_at' => $new_expiry, 'status' => 'active' ] );
        if ( $row->user_id ) {
            update_user_meta( (int) $row->user_id, '_ccdemo_expires_at', $new_expiry );
        }
    }

    private function redirect_notice( $notice ) {
        wp_safe_redirect( add_query_arg( 'ccdemo_notice', $notice, admin_url( 'admin.php?page=ccdemo-manager' ) ) );
        exit;
    }

    public function action_notices(): void {
        $notice = sanitize_key( $_GET['ccdemo_notice'] ?? '' );
        if ( ! $notice ) { return; }

        if ( strpos( $notice, 'cleanup_' ) === 0 ) {
            $count = (int) str_replace( 'cleanup_', '', $notice );
            printf( '<div class="notice notice-success is-dismissible"><p>Cleanup complete: <strong>%d</strong> expired session(s) removed.</p></div>', $count );
            return;
        }

        $map = [
            'deleted'  => 'Session deleted.',
            'extended' => 'Session extended by 1 day.',
        ];

        if ( isset( $map[ $notice ] ) ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $notice ] ) );
        }
    }

    /* ------------------------------------------------------------------
     * Reusable page fragments
     * ------------------------------------------------------------------ */

    private function shortcode_reference(): void {
        ?>
        <h2 style="margin-top:28px;">Shortcode</h2>
        <p>Add to any page or post on <strong>codeconfig.dev</strong>:</p>
        <code>[codeconfig_demo_form]</code>
        &emsp;
        <code>[codeconfig_demo_form title="Book a Live Demo"]</code>
        <?php
    }

    private function how_it_works(): void {
        ?>
        <h2 style="margin-top:28px;">How It Works</h2>
        <ol style="line-height:2.1;max-width:660px;">
            <li>Lead selects a product on <strong>codeconfig.dev</strong></li>
            <li>Plugin looks up the <strong>Demo Subdomain URL</strong> for that product</li>
            <li>Sends a signed API request to that subdomain (e.g. <code>igd.codeconfig.dev</code>)</li>
            <li>That site creates a temp WP user + session and returns a magic link</li>
            <li>This site emails the magic link to the lead</li>
            <li>Lead clicks → auto-logged into <strong>igd.codeconfig.dev/wp-admin</strong></li>
            <li>WP-Cron on each demo site auto-deletes accounts after expiry</li>
        </ol>
        <?php
    }
}
