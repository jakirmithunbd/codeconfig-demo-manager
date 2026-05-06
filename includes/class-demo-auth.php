<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_Auth {

    public function __construct() {
        // Intercept the magic link on every page load (before headers sent)
        add_action( 'init', [ $this, 'maybe_authenticate' ], 1 );

        // Register a lightweight custom role for demo users
        add_action( 'init', [ $this, 'register_demo_role' ] );

        // Block demo users from accessing the front-end — force them into the admin
        add_action( 'template_redirect', [ $this, 'redirect_demo_user_to_admin' ] );

        // Show an admin notice to demo users explaining the expiry
        add_action( 'admin_notices', [ $this, 'demo_expiry_notice' ] );

        // Remove admin bar links that a demo user shouldn't click
        add_action( 'admin_bar_menu', [ $this, 'clean_admin_bar' ], 999 );

        // Prevent demo users from accessing profile / password change
        add_action( 'personal_options_update',  [ $this, 'block_profile_update' ] );
        add_action( 'edit_user_profile_update', [ $this, 'block_profile_update' ] );
    }

    /* ------------------------------------------------------------------
     * Magic-link authentication
     * ------------------------------------------------------------------ */

    public function maybe_authenticate(): void {
        $token = sanitize_text_field( $_GET['ccdemo_token'] ?? '' );
        if ( empty( $token ) ) {
            return;
        }

        $session = CCDemo_DB::get_session_by_token( $token );

        if ( ! $session ) {
            wp_die( '<h2>Invalid Demo Link</h2><p>This demo link is not recognised. Please request a new one.</p>', 'Invalid Demo Link', [ 'response' => 403 ] );
        }

        // Check expiry
        if ( strtotime( $session->expires_at ) < time() ) {
            wp_die( '<h2>Demo Expired</h2><p>This demo link has expired. Please request a new demo.</p>', 'Demo Expired', [ 'response' => 410 ] );
        }

        // Check status
        if ( ! in_array( $session->status, [ 'pending', 'active' ], true ) ) {
            wp_die( '<h2>Demo Unavailable</h2><p>This demo session is no longer available.</p>', 'Demo Unavailable', [ 'response' => 410 ] );
        }

        $user = get_user_by( 'id', $session->user_id );
        if ( ! $user ) {
            wp_die( '<h2>Demo Account Not Found</h2><p>The demo account has been removed. Please request a new demo.</p>', 'Account Not Found', [ 'response' => 404 ] );
        }

        // Log the user in
        wp_set_auth_cookie( $user->ID, false );
        wp_set_current_user( $user->ID );

        // Mark session active + record access time
        CCDemo_DB::update_session( (int) $session->id, [
            'status'      => 'active',
            'accessed_at' => current_time( 'mysql' ),
        ] );

        // Redirect to the admin demo landing page
        $redirect = apply_filters( 'ccdemo_login_redirect', admin_url( 'index.php?ccdemo=1' ), $session );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ------------------------------------------------------------------
     * Custom role with read-only capabilities
     * ------------------------------------------------------------------ */

    public function register_demo_role(): void {
        if ( get_role( 'ccdemo_user' ) ) {
            return;
        }

        $caps = apply_filters( 'ccdemo_role_caps', [
            'read'                   => true,
            'upload_files'           => false,
            'edit_posts'             => false,
            'delete_posts'           => false,
            'publish_posts'          => false,
            'edit_pages'             => false,
            'manage_options'         => false,
            'list_users'             => false,
        ] );

        add_role( 'ccdemo_user', 'Demo User', $caps );
    }

    /* ------------------------------------------------------------------
     * Force demo users straight to WP Admin (no front-end browsing)
     * ------------------------------------------------------------------ */

    public function redirect_demo_user_to_admin(): void {
        if ( is_admin() || ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! in_array( 'ccdemo_user', (array) $user->roles, true ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'index.php?ccdemo=1' ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Admin notice shown to the demo user inside WP Admin
     * ------------------------------------------------------------------ */

    public function demo_expiry_notice(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! in_array( 'ccdemo_user', (array) $user->roles, true ) ) {
            return;
        }

        $expires_at = get_user_meta( $user->ID, '_ccdemo_expires_at', true );
        $product    = get_user_meta( $user->ID, '_ccdemo_product', true );
        $products   = CCDemo_Form::get_products();
        $label      = $products[ $product ] ?? $product;

        if ( ! $expires_at ) {
            return;
        }

        $diff_seconds = strtotime( $expires_at ) - time();
        $diff_hours   = max( 0, round( $diff_seconds / HOUR_IN_SECONDS ) );

        if ( $diff_seconds <= 0 ) {
            $time_left = 'expired';
        } elseif ( $diff_hours < 24 ) {
            $time_left = $diff_hours . ' hour(s)';
        } else {
            $time_left = round( $diff_hours / 24 ) . ' day(s)';
        }

        printf(
            '<div class="notice notice-info" style="border-left-color:#4F46E5;">
                <p>&#127881; <strong>Welcome to your %s demo!</strong>
                This is a private, time-limited demo environment. It will be automatically deleted in <strong>%s</strong>.
                </p>
            </div>',
            esc_html( $label ),
            esc_html( $time_left )
        );
    }

    /* ------------------------------------------------------------------
     * Clean admin bar for demo users
     * ------------------------------------------------------------------ */

    public function clean_admin_bar( WP_Admin_Bar $bar ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $user = wp_get_current_user();
        if ( ! in_array( 'ccdemo_user', (array) $user->roles, true ) ) {
            return;
        }

        $remove = [ 'new-content', 'comments', 'updates', 'edit' ];
        foreach ( $remove as $node ) {
            $bar->remove_node( $node );
        }
    }

    /* ------------------------------------------------------------------
     * Block profile / password updates for demo users
     * ------------------------------------------------------------------ */

    public function block_profile_update( int $user_id ): void {
        $user = get_user_by( 'id', $user_id );
        if ( $user && in_array( 'ccdemo_user', (array) $user->roles, true ) ) {
            wp_die( 'Profile updates are disabled in the demo environment.', 'Demo Restriction', [ 'back_link' => true ] );
        }
    }
}
