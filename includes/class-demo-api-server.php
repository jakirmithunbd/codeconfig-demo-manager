<?php
/**
 * REST API Server — runs on each demo subdomain.
 *
 * Endpoints:
 *   POST /wp-json/ccdemo/v1/create-session
 *   GET  /wp-json/ccdemo/v1/health
 *
 * Every request must carry:
 *   Authorization: Bearer <api_key>
 *   X-CCDemo-Timestamp: <unix_ts>   (±5 min of server time)
 *   X-CCDemo-Nonce: <random_hex>    (one-time, stored 10 min)
 *
 * Compatible with PHP 7.4+.
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_API_Server {

    const NS       = 'ccdemo/v1';
    const NONCE_TTL = 600; // seconds

    public function __construct() {
        add_action( 'rest_api_init',   array( $this, 'register_routes' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'enforce_https' ), 10, 3 );
    }

    /* ------------------------------------------------------------------
     * Route registration
     * ------------------------------------------------------------------ */

    public function register_routes() {
        register_rest_route( self::NS, '/create-session', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'create_session' ),
            'permission_callback' => array( $this, 'authenticate' ),
            'args'                => $this->create_session_args(),
        ) );

        register_rest_route( self::NS, '/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'health_check' ),
            'permission_callback' => array( $this, 'authenticate' ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Authentication
     * ------------------------------------------------------------------ */

    public function authenticate( $request ) {
        // 1. API key
        $auth_header = (string) $request->get_header( 'Authorization' );
        if ( strpos( $auth_header, 'Bearer ' ) !== 0 ) {
            return new WP_Error( 'missing_auth', 'Authorization header required.', array( 'status' => 401 ) );
        }

        $provided_key = substr( $auth_header, 7 );
        $stored_key   = (string) get_option( 'ccdemo_api_key', '' );

        if ( empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
            $this->audit_log( 'AUTH_FAIL', $request->get_header( 'X-Forwarded-For' ) ?: ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) );
            return new WP_Error( 'invalid_key', 'Invalid API key.', array( 'status' => 401 ) );
        }

        // 2. Timestamp replay protection (±5 minutes)
        $timestamp = (int) $request->get_header( 'X-CCDemo-Timestamp' );
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'replay_attack', 'Request timestamp out of range.', array( 'status' => 400 ) );
        }

        // 3. One-time nonce
        $nonce = sanitize_text_field( (string) $request->get_header( 'X-CCDemo-Nonce' ) );
        if ( empty( $nonce ) || strlen( $nonce ) < 16 ) {
            return new WP_Error( 'missing_nonce', 'X-CCDemo-Nonce header required.', array( 'status' => 400 ) );
        }

        $nonce_key = 'ccdemo_nonce_' . hash( 'sha256', $nonce );
        if ( get_transient( $nonce_key ) ) {
            return new WP_Error( 'nonce_replayed', 'Nonce already used.', array( 'status' => 400 ) );
        }
        set_transient( $nonce_key, 1, self::NONCE_TTL );

        return true;
    }

    /* ------------------------------------------------------------------
     * Enforce HTTPS
     * ------------------------------------------------------------------ */

    public function enforce_https( $result, $server, $request ) {
        if ( strpos( $request->get_route(), '/' . self::NS ) !== 0 ) {
            return $result;
        }
        if ( ! is_ssl() && ! ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) ) {
            return new WP_Error( 'ssl_required', 'HTTPS is required.', array( 'status' => 403 ) );
        }
        return $result;
    }

    /* ------------------------------------------------------------------
     * POST /ccdemo/v1/create-session
     * ------------------------------------------------------------------ */

    public function create_session( $request ) {
        $name    = (string) $request->get_param( 'name' );
        $email   = (string) $request->get_param( 'email' );
        $company = (string) ( $request->get_param( 'company' ) ?: '' );
        $phone   = (string) ( $request->get_param( 'phone' )   ?: '' );
        $product = (string) $request->get_param( 'product' );
        $ip      = (string) ( $request->get_param( 'ip' )      ?: '' );

        // Duplicate active session check
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . CCDEMO_TABLE .
            " WHERE email = %s AND product = %s AND status IN ('pending','active') AND expires_at > %s LIMIT 1",
            $email, $product, current_time( 'mysql' )
        ) );

        if ( $existing ) {
            return new WP_Error(
                'duplicate_session',
                'An active demo session already exists for this email and product.',
                array( 'status' => 409 )
            );
        }

        // Create temp WP user
        $expiry_days = (int) get_option( 'ccdemo_expiry_days', 2 );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + $expiry_days * DAY_IN_SECONDS );
        $base        = strtolower( preg_replace( '/[^a-z0-9]/i', '', $name ) );
        $username    = 'demo_' . $base . '_' . bin2hex( random_bytes( 4 ) );
        $password    = wp_generate_password( 24, true, true );

        $user_id = wp_create_user( $username, $password, $username . '@demo.ccdemo.internal' );

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'user_creation_failed', 'Could not create demo user.', array( 'status' => 500 ) );
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'ccdemo_user' );

        update_user_meta( $user_id, '_ccdemo_expires_at', $expires_at );
        update_user_meta( $user_id, '_ccdemo_product',    $product );
        update_user_meta( $user_id, '_ccdemo_real_email', sanitize_email( $email ) );
        update_user_meta( $user_id, '_ccdemo_real_name',  sanitize_text_field( $name ) );

        $token = bin2hex( random_bytes( 32 ) );

        $session_id = CCDemo_DB::insert_session( array(
            'name'       => $name,
            'email'      => $email,
            'company'    => $company,
            'phone'      => $phone,
            'product'    => $product,
            'token'      => $token,
            'user_id'    => $user_id,
            'expires_at' => $expires_at,
            'ip'         => $ip,
        ) );

        if ( ! $session_id ) {
            wp_delete_user( $user_id );
            return new WP_Error( 'session_creation_failed', 'Could not persist demo session.', array( 'status' => 500 ) );
        }

        $magic_link = add_query_arg( 'ccdemo_token', $token, home_url( '/' ) );

        return new WP_REST_Response( array(
            'success'    => true,
            'token'      => $token,
            'magic_link' => $magic_link,
            'expires_at' => $expires_at,
            'session_id' => $session_id,
        ), 201 );
    }

    /* ------------------------------------------------------------------
     * GET /ccdemo/v1/health
     * ------------------------------------------------------------------ */

    public function health_check( $request ) {
        global $wpdb;
        $table_exists = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}" . CCDEMO_TABLE . "'"
        );

        return new WP_REST_Response( array(
            'status'      => 'ok',
            'version'     => CCDEMO_VERSION,
            'mode'        => 'demo',
            'db_table'    => $table_exists,
            'server_time' => gmdate( 'c' ),
        ) );
    }

    /* ------------------------------------------------------------------
     * Argument schema
     * ------------------------------------------------------------------ */

    private function create_session_args() {
        return array(
            'name'    => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            'email'   => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email',      'format' => 'email' ),
            'product' => array( 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
            'company' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
            'phone'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
            'ip'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
        );
    }

    private function audit_log( $event, $ip ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( '[CCDemo][AUDIT] %s from %s at %s', $event, $ip, gmdate( 'c' ) ) );
        }
    }
}
