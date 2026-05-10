<?php
/**
 * Magic-link authentication — runs on demo subdomains. Compatible with PHP 7.4+.
 *
 * Demo users are plain subscribers. They are identified as demo users via the
 * _ccdemo_expires_at user meta set at creation time.
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Auth {

    public function __construct() {
        add_action( 'init',                  array( $this, 'maybe_authenticate' ), 1 );
        add_action( 'template_redirect',     array( $this, 'redirect_demo_user_to_admin' ) );
        add_action( 'admin_notices',         array( $this, 'demo_expiry_notice' ) );
        add_action( 'admin_bar_menu',        array( $this, 'clean_admin_bar' ), 999 );
        add_action( 'admin_menu',            array( $this, 'restrict_admin_menu' ), 999 );
        add_action( 'personal_options_update',  array( $this, 'block_profile_update' ) );
        add_action( 'edit_user_profile_update', array( $this, 'block_profile_update' ) );
        add_filter( 'rest_authentication_errors', array( $this, 'restrict_rest_for_demo' ) );
        add_filter( 'show_password_fields', array( $this, 'hide_password_fields' ) );
    }

    /* ------------------------------------------------------------------
     * Magic-link login
     * ------------------------------------------------------------------ */

    public function maybe_authenticate() {
        $token = isset( $_GET['ccdemo_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ccdemo_token'] ) ) : '';

        if ( empty( $token ) || strlen( $token ) !== 64 ) {
            return;
        }

        if ( ! ctype_xdigit( $token ) ) {
            wp_die( $this->error_html( 'Invalid Token', 'This demo link is malformed.' ), 'Invalid Token', array( 'response' => 400 ) );
        }

        $session = CCDemo_DB::get_session_by_token( $token );

        if ( ! $session ) {
            wp_die( $this->error_html( 'Invalid Demo Link', 'This demo link was not recognised. Please request a new one.' ), 'Invalid Link', array( 'response' => 403 ) );
        }

        if ( strtotime( $session->expires_at ) < time() ) {
            wp_die( $this->error_html( 'Demo Expired', 'This demo has expired. Please visit codeconfig.dev to request a new demo.' ), 'Demo Expired', array( 'response' => 410 ) );
        }

        if ( ! in_array( $session->status, array( 'pending', 'active' ), true ) ) {
            wp_die( $this->error_html( 'Demo Unavailable', 'This demo session is no longer available.' ), 'Unavailable', array( 'response' => 410 ) );
        }

        $user = get_user_by( 'id', (int) $session->user_id );
        if ( ! $user || ! $this->user_is_demo( $user->ID ) ) {
            wp_die( $this->error_html( 'Account Not Found', 'The demo account was not found. Please request a new demo.' ), 'Not Found', array( 'response' => 404 ) );
        }

        // Set auth cookie whose lifetime matches the demo session expiry.
        $cookie_expiry = max( 3600, strtotime( $session->expires_at ) - time() );
        $target_uid    = $user->ID;

        $expiry_filter = function ( $length, $filter_uid ) use ( $target_uid, $cookie_expiry ) {
            return ( (int) $filter_uid === (int) $target_uid ) ? $cookie_expiry : $length;
        };
        add_filter( 'auth_cookie_expiration', $expiry_filter, 10, 2 );

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        remove_filter( 'auth_cookie_expiration', $expiry_filter, 10 );

        do_action( 'wp_login', $user->user_login, $user );

        CCDemo_DB::update_session( (int) $session->id, array(
            'status'      => 'active',
            'accessed_at' => current_time( 'mysql' ),
        ) );

        $redirect = apply_filters( 'ccdemo_login_redirect', admin_url( 'index.php?ccdemo=welcome' ), $session );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ------------------------------------------------------------------
     * Force demo users to WP admin
     * ------------------------------------------------------------------ */

    public function redirect_demo_user_to_admin() {
        if ( is_admin() || ! is_user_logged_in() ) {
            return;
        }
        if ( $this->current_user_is_demo() ) {
            wp_safe_redirect( admin_url() );
            exit;
        }
    }

    /* ------------------------------------------------------------------
     * Admin notice
     * ------------------------------------------------------------------ */

    public function demo_expiry_notice() {
        if ( ! $this->current_user_is_demo() ) {
            return;
        }

        $user       = wp_get_current_user();
        $expires_at = get_user_meta( $user->ID, '_ccdemo_expires_at', true );
        $product    = get_user_meta( $user->ID, '_ccdemo_product', true );
        $products   = (array) get_option( 'ccdemo_products_v2', array() );
        $label      = isset( $products[ $product ]['label'] ) ? $products[ $product ]['label'] : $product;

        if ( ! $expires_at ) {
            return;
        }

        $left_sec  = strtotime( $expires_at ) - time();
        $main_url  = esc_url( get_option( 'ccdemo_main_site_url', 'https://codeconfig.dev' ) );

        if ( $left_sec <= 0 ) {
            $time_left = '<strong style="color:#dc2626;">expired</strong>';
        } elseif ( $left_sec < DAY_IN_SECONDS ) {
            $time_left = '<strong>' . round( $left_sec / HOUR_IN_SECONDS ) . ' hour(s)</strong>';
        } else {
            $time_left = '<strong>' . round( $left_sec / DAY_IN_SECONDS ) . ' day(s)</strong>';
        }

        printf(
            '<div class="notice notice-info" style="border-left-color:#4F46E5;padding:12px 16px;">
              <p>&#127881; <strong>Welcome to your %s demo!</strong>
              This demo will be automatically removed in %s.
              <a href="%s" style="margin-left:8px;">Request another demo &rarr;</a>
              </p>
            </div>',
            esc_html( $label ),
            $time_left,
            $main_url
        );
    }

    /* ------------------------------------------------------------------
     * Restrict admin menu
     * ------------------------------------------------------------------ */

    public function restrict_admin_menu() {
        if ( ! $this->current_user_is_demo() ) {
            return;
        }

        $allowed = apply_filters( 'ccdemo_allowed_menu_slugs', array( 'index.php' ) );

        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }
        foreach ( $menu as $item ) {
            if ( isset( $item[2] ) && ! in_array( $item[2], $allowed, true ) ) {
                remove_menu_page( $item[2] );
            }
        }
    }

    /* ------------------------------------------------------------------
     * Clean admin bar
     * ------------------------------------------------------------------ */

    public function clean_admin_bar( $bar ) {
        if ( ! $this->current_user_is_demo() ) {
            return;
        }
        foreach ( array( 'new-content', 'comments', 'updates', 'edit', 'wp-logo', 'site-name' ) as $node ) {
            $bar->remove_node( $node );
        }
    }

    /* ------------------------------------------------------------------
     * Block profile updates
     * ------------------------------------------------------------------ */

    public function block_profile_update( $user_id ) {
        if ( $this->user_is_demo( (int) $user_id ) ) {
            wp_die( 'Profile updates are disabled in the demo environment.', 'Demo Restriction', array( 'back_link' => true ) );
        }
    }

    public function hide_password_fields( $show ) {
        if ( is_user_logged_in() && $this->current_user_is_demo() ) {
            return false;
        }
        return $show;
    }

    /* ------------------------------------------------------------------
     * Block REST API for demo users
     * ------------------------------------------------------------------ */

    public function restrict_rest_for_demo( $errors ) {
        if ( $errors || ! is_user_logged_in() || ! $this->current_user_is_demo() ) {
            return $errors;
        }

        $route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        if ( strpos( $route, '/ccdemo/v1' ) === 0 ) {
            return $errors; // allow own namespace
        }

        return new WP_Error( 'demo_rest_blocked', 'REST API is restricted in the demo environment.', array( 'status' => 403 ) );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function current_user_is_demo() {
        return is_user_logged_in() && $this->user_is_demo( get_current_user_id() );
    }

    private function user_is_demo( $user_id ) {
        return (bool) get_user_meta( (int) $user_id, '_ccdemo_expires_at', true );
    }

    private function error_html( $title, $body ) {
        $main_url  = esc_url( get_option( 'ccdemo_main_site_url', 'https://codeconfig.dev' ) );
        $primary   = '#4F46E5';
        return sprintf(
            '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f9fafb}
            .box{background:#fff;border-radius:12px;padding:40px 48px;max-width:480px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}
            h2{color:#111827;margin:0 0 12px}p{color:#6b7280;line-height:1.6}a{color:%s;font-weight:600}</style>
            <div class="box"><h2>%s</h2><p>%s</p><a href="%s">Request a new demo &rarr;</a></div>',
            $primary,
            esc_html( $title ),
            esc_html( $body ),
            $main_url
        );
    }
}
