<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_Admin {

    public function __construct() {
        add_action( 'admin_menu',       [ $this, 'add_menu' ] );
        add_action( 'admin_init',       [ $this, 'register_settings' ] );
        add_action( 'admin_init',       [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_notices',    [ $this, 'action_notices' ] );
    }

    /* ------------------------------------------------------------------
     * Admin menu
     * ------------------------------------------------------------------ */

    public function add_menu(): void {
        add_menu_page(
            'Demo Manager',
            'Demo Manager',
            'manage_options',
            'ccdemo-manager',
            [ $this, 'page_sessions' ],
            'dashicons-welcome-view-site',
            30
        );

        add_submenu_page(
            'ccdemo-manager',
            'Demo Sessions',
            'Sessions',
            'manage_options',
            'ccdemo-manager',
            [ $this, 'page_sessions' ]
        );

        add_submenu_page(
            'ccdemo-manager',
            'Demo Settings',
            'Settings',
            'manage_options',
            'ccdemo-settings',
            [ $this, 'page_settings' ]
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_ccdemo-manager', 'demo-manager_page_ccdemo-settings' ], true ) ) {
            return;
        }
        wp_enqueue_style( 'ccdemo-admin', CCDEMO_URL . 'assets/css/admin.css', [], CCDEMO_VERSION );
    }

    /* ------------------------------------------------------------------
     * Sessions page
     * ------------------------------------------------------------------ */

    public function page_sessions(): void {
        $sessions  = CCDemo_DB::get_all_sessions( 500 );
        $products  = CCDemo_Form::get_products();
        $now       = current_time( 'timestamp' );

        $status_labels = [
            'pending' => '<span class="ccdemo-badge badge-pending">Pending</span>',
            'active'  => '<span class="ccdemo-badge badge-active">Active</span>',
            'expired' => '<span class="ccdemo-badge badge-expired">Expired</span>',
        ];
        ?>
        <div class="wrap ccdemo-admin-wrap">
            <h1 class="wp-heading-inline">Demo Sessions</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccdemo-manager&action=cleanup&_wpnonce=' . wp_create_nonce( 'ccdemo_cleanup' ) ) ); ?>"
               class="page-title-action">Run Cleanup Now</a>
            <hr class="wp-header-end">

            <!-- Stats bar -->
            <?php
            $total   = count( $sessions );
            $active  = count( array_filter( $sessions, fn( $s ) => $s->status === 'active' ) );
            $pending = count( array_filter( $sessions, fn( $s ) => $s->status === 'pending' ) );
            $expired = count( array_filter( $sessions, fn( $s ) => $s->status === 'expired' ) );
            ?>
            <div class="ccdemo-stats">
                <div class="ccdemo-stat"><span><?php echo $total; ?></span>Total</div>
                <div class="ccdemo-stat stat-active"><span><?php echo $active; ?></span>Active</div>
                <div class="ccdemo-stat stat-pending"><span><?php echo $pending; ?></span>Pending</div>
                <div class="ccdemo-stat stat-expired"><span><?php echo $expired; ?></span>Expired</div>
            </div>

            <table class="wp-list-table widefat fixed striped ccdemo-sessions-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Name / Email</th>
                        <th>Company</th>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th>IP</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $sessions ) ) : ?>
                    <tr><td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;">No demo sessions yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $sessions as $s ) :
                        $expires_ts  = strtotime( $s->expires_at );
                        $is_expired  = $expires_ts < $now;
                        $row_class   = $is_expired ? 'ccdemo-row-expired' : '';
                        $product_label = $products[ $s->product ] ?? $s->product;
                        $delete_url  = wp_nonce_url(
                            admin_url( 'admin.php?page=ccdemo-manager&action=delete&session_id=' . $s->id ),
                            'ccdemo_delete_' . $s->id
                        );
                        $extend_url  = wp_nonce_url(
                            admin_url( 'admin.php?page=ccdemo-manager&action=extend&session_id=' . $s->id ),
                            'ccdemo_extend_' . $s->id
                        );
                    ?>
                    <tr class="<?php echo esc_attr( $row_class ); ?>">
                        <td><?php echo (int) $s->id; ?></td>
                        <td>
                            <strong><?php echo esc_html( $s->name ); ?></strong><br>
                            <a href="mailto:<?php echo esc_attr( $s->email ); ?>">
                                <?php echo esc_html( $s->email ); ?>
                            </a>
                            <?php if ( $s->phone ) : ?>
                                <br><small><?php echo esc_html( $s->phone ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $s->company ?: '—' ); ?></td>
                        <td><?php echo esc_html( $product_label ); ?></td>
                        <td><?php echo $status_labels[ $s->status ] ?? esc_html( $s->status ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y H:i', strtotime( $s->created_at ) ) ); ?></td>
                        <td>
                            <?php echo esc_html( date_i18n( 'M j, Y H:i', $expires_ts ) ); ?>
                            <?php if ( ! $is_expired && $s->status !== 'expired' ) : ?>
                                <br><small style="color:#16a34a;">
                                    <?php
                                    $left = $expires_ts - $now;
                                    echo $left > DAY_IN_SECONDS
                                        ? round( $left / DAY_IN_SECONDS ) . 'd left'
                                        : round( $left / HOUR_IN_SECONDS ) . 'h left';
                                    ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $s->ip_address ?: '—' ); ?></td>
                        <td>
                            <?php if ( $s->status !== 'expired' ) : ?>
                                <a href="<?php echo esc_url( $extend_url ); ?>"
                                   class="button button-small" title="Extend by 1 day">+1 Day</a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $delete_url ); ?>"
                               class="button button-small button-link-delete"
                               onclick="return confirm('Delete this demo session and its user account?')">Delete</a>
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
        ?>
        <div class="wrap ccdemo-admin-wrap">
            <h1>Demo Manager — Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'ccdemo_settings_group' );
                do_settings_sections( 'ccdemo-settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr>
            <h2>Shortcode Reference</h2>
            <p>Add the product selection + lead form to any page or post:</p>
            <code>[codeconfig_demo_form]</code>
            <p>With a custom title:</p>
            <code>[codeconfig_demo_form title="Book a Live Demo"]</code>

            <hr>
            <h2>Product List</h2>
            <p>Configure the products shown in the demo form below (one per line, format: <code>slug|Label</code>).</p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Register settings
     * ------------------------------------------------------------------ */

    public function register_settings(): void {
        // General section
        add_settings_section( 'ccdemo_general', 'General', '__return_false', 'ccdemo-settings' );

        $general_fields = [
            [ 'ccdemo_expiry_days',    'Demo Duration (days)',      'number', '2',    'How many days before a demo account is auto-deleted.' ],
            [ 'ccdemo_from_name',      'Email From Name',           'text',   '',     'Sender name in the demo invitation email.' ],
            [ 'ccdemo_from_email',     'Email From Address',        'email',  '',     'Sender email address.' ],
            [ 'ccdemo_primary_color',  'Brand Color (hex)',         'text',   '#4F46E5', 'Used in the email template and form.' ],
            [ 'ccdemo_email_logo',     'Email Logo URL',            'url',    '',     'Full URL to your logo image (appears in the email header).' ],
            [ 'ccdemo_admin_notify',   'Notify Admin on Request',   'checkbox', '1', 'Send admin an email for each new demo request.' ],
        ];

        foreach ( $general_fields as [ $id, $label, $type, $default, $desc ] ) {
            register_setting( 'ccdemo_settings_group', $id, [
                'sanitize_callback' => $type === 'number' ? 'absint' : 'sanitize_text_field',
                'default'           => $default,
            ] );
            add_settings_field( $id, $label, function () use ( $id, $type, $default, $desc ) {
                $val = get_option( $id, $default );
                if ( $type === 'checkbox' ) {
                    echo "<input type='checkbox' name='{$id}' value='1' " . checked( 1, (int) $val, false ) . "> <span class='description'>{$desc}</span>";
                } elseif ( $type === 'number' ) {
                    echo "<input type='number' name='{$id}' value='" . esc_attr( $val ) . "' min='1' max='30' style='width:80px'> <span class='description'>{$desc}</span>";
                } else {
                    echo "<input type='{$type}' name='{$id}' value='" . esc_attr( $val ) . "' style='width:320px'> <span class='description'>{$desc}</span>";
                }
            }, 'ccdemo-settings', 'ccdemo_general' );
        }

        // Products section
        add_settings_section( 'ccdemo_products_section', 'Products', function () {
            echo '<p>One product per line. Format: <code>slug|Product Label</code></p>';
        }, 'ccdemo-settings' );

        register_setting( 'ccdemo_settings_group', 'ccdemo_products_raw', [
            'sanitize_callback' => [ $this, 'sanitize_products' ],
        ] );

        add_settings_field( 'ccdemo_products_raw', 'Products', function () {
            $raw = get_option( 'ccdemo_products_raw', "google-drive|Integration for Google Drive\ndropbox|Integration for Dropbox\nonedrive|Integration for OneDrive" );
            echo "<textarea name='ccdemo_products_raw' rows='8' style='width:400px;font-family:monospace'>" . esc_textarea( $raw ) . "</textarea>";
        }, 'ccdemo-settings', 'ccdemo_products_section' );
    }

    public function sanitize_products( string $raw ): string {
        $raw   = sanitize_textarea_field( $raw );
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $clean = [];
        $out   = [];

        foreach ( $lines as $line ) {
            if ( strpos( $line, '|' ) === false ) {
                continue;
            }
            [ $slug, $label ] = explode( '|', $line, 2 );
            $slug  = sanitize_key( trim( $slug ) );
            $label = sanitize_text_field( trim( $label ) );
            if ( $slug && $label ) {
                $out[] = "{$slug}|{$label}";
                $clean[ $slug ] = $label;
            }
        }

        // Also persist as the structured option
        update_option( 'ccdemo_products', $clean );

        return implode( "\n", $out );
    }

    /* ------------------------------------------------------------------
     * Handle admin actions (delete, extend, cleanup)
     * ------------------------------------------------------------------ */

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action     = sanitize_key( $_GET['action']     ?? '' );
        $session_id = (int) ( $_GET['session_id']       ?? 0 );

        switch ( $action ) {

            case 'delete':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ccdemo_delete_' . $session_id ) ) {
                    wp_die( 'Security check failed.' );
                }
                CCDemo_Cron::delete_session_now( $session_id );
                wp_safe_redirect( add_query_arg( 'ccdemo_notice', 'deleted', admin_url( 'admin.php?page=ccdemo-manager' ) ) );
                exit;

            case 'extend':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ccdemo_extend_' . $session_id ) ) {
                    wp_die( 'Security check failed.' );
                }
                $this->extend_session( $session_id, 1 );
                wp_safe_redirect( add_query_arg( 'ccdemo_notice', 'extended', admin_url( 'admin.php?page=ccdemo-manager' ) ) );
                exit;

            case 'cleanup':
                if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ccdemo_cleanup' ) ) {
                    wp_die( 'Security check failed.' );
                }
                $count = CCDemo_Cron::run_manual_cleanup();
                wp_safe_redirect( add_query_arg( 'ccdemo_notice', 'cleanup_' . $count, admin_url( 'admin.php?page=ccdemo-manager' ) ) );
                exit;
        }
    }

    private function extend_session( int $session_id, int $days = 1 ): void {
        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE id = %d",
            $session_id
        ) );
        if ( ! $session ) {
            return;
        }

        $new_expiry = gmdate( 'Y-m-d H:i:s', strtotime( $session->expires_at ) + $days * DAY_IN_SECONDS );
        CCDemo_DB::update_session( $session_id, [
            'expires_at' => $new_expiry,
            'status'     => 'active',
        ] );
        if ( $session->user_id ) {
            update_user_meta( (int) $session->user_id, '_ccdemo_expires_at', $new_expiry );
        }
    }

    public function action_notices(): void {
        $notice = sanitize_key( $_GET['ccdemo_notice'] ?? '' );
        if ( ! $notice ) {
            return;
        }

        $messages = [
            'deleted'  => [ 'success', 'Demo session deleted.' ],
            'extended' => [ 'success', 'Demo session extended by 1 day.' ],
        ];

        if ( str_starts_with( $notice, 'cleanup_' ) ) {
            $count = (int) str_replace( 'cleanup_', '', $notice );
            $messages['cleanup_' . $count] = [ 'success', "Manual cleanup complete: {$count} expired session(s) removed." ];
        }

        if ( isset( $messages[ $notice ] ) ) {
            [ $type, $msg ] = $messages[ $notice ];
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
        }
    }
}
