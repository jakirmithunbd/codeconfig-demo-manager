<?php
/**
 * REST API Server — runs on demo.codeconfig.dev
 *
 * Endpoints:
 *   POST /wp-json/ccdemo/v1/create-session   → create temp user + session
 *   GET  /wp-json/ccdemo/v1/health            → liveness probe
 *
 * Authentication: every request must include:
 *   Authorization: Bearer <api_key>
 *   X-CCDemo-Timestamp: <unix_timestamp>        (within ±300 s of server time)
 *   X-CCDemo-Nonce: <random_string>             (used once, stored 10 min)
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_API_Server {

    private const NS      = 'ccdemo/v1';
    private const NONCE_TTL = 600; // 10 minutes

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        // Enforce HTTPS on every REST request to this namespace
        add_filter( 'rest_pre_dispatch', [ $this, 'enforce_https' ], 10, 3 );
    }

    /* ------------------------------------------------------------------
     * Route registration
     * ------------------------------------------------------------------ */

    public function register_routes(): void {
        register_rest_route( self::NS, '/create-session', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_session' ],
            'permission_callback' => [ $this, 'authenticate' ],
            'args'                => $this->create_session_args(),
        ] );

        register_rest_route( self::NS, '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'health_check' ],
            'permission_callback' => [ $this, 'authenticate' ],
        ] );
    }

    /* ------------------------------------------------------------------
     * Authentication & security checks
     * ------------------------------------------------------------------ */

    public function authenticate( WP_REST_Request $request ): bool|WP_Error {
        // 1. API key
        $auth_header = $request->get_header( 'Authorization' ) ?? '';
        if ( ! str_starts_with( $auth_header, 'Bearer ' ) ) {
            return new WP_Error( 'missing_auth', 'Authorization header required.', [ 'status' => 401 ] );
        }

        $provided_key = substr( $auth_header, 7 );
        $stored_key   = get_option( 'ccdemo_api_key', '' );

        if ( empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
            $this->audit_log( 'AUTH_FAIL', $request->get_header( 'X-Forwarded-For' ) ?? $_SERVER['REMOTE_ADDR'] ?? '' );
            return new WP_Error( 'invalid_key', 'Invalid API key.', [ 'status' => 401 ] );
        }

        // 2. Timestamp replay protection (±5 minutes)
        $timestamp = (int) $request->get_header( 'X-CCDemo-Timestamp' );
        if ( abs( time() - $timestamp ) > 300 ) {
            return new WP_Error( 'replay_attack', 'Request timestamp out of range.', [ 'status' => 400 ] );
        }

        // 3. Nonce — one-time use
        $nonce = sanitize_text_field( $request->get_header( 'X-CCDemo-Nonce' ) ?? '' );
        if ( empty( $nonce ) || strlen( $nonce ) < 16 ) {
            return new WP_Error( 'missing_nonce', 'X-CCDemo-Nonce header required.', [ 'status' => 400 ] );
        }

        $nonce_key = 'ccdemo_nonce_' . hash( 'sha256', $nonce );
        if ( get_transient( $nonce_key ) ) {
            return new WP_Error( 'nonce_replayed', 'Nonce already used.', [ 'status' => 400 ] );
        }
        set_transient( $nonce_key, 1, self::NONCE_TTL );

        return true;
    }

    /* ------------------------------------------------------------------
     * Enforce HTTPS
     * ------------------------------------------------------------------ */

    public function enforce_https( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
        if ( ! str_starts_with( $request->get_route(), '/' . self::NS ) ) {
            return $result;
        }

        if ( ! is_ssl() && ! ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) ) {
            return new WP_Error( 'ssl_required', 'HTTPS is required.', [ 'status' => 403 ] );
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     * POST /ccdemo/v1/create-session
     * ------------------------------------------------------------------ */

    public function create_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $name    = $request->get_param( 'name' );
        $email   = $request->get_param( 'email' );
        $company = $request->get_param( 'company' ) ?? '';
        $phone   = $request->get_param( 'phone' )   ?? '';
        $product = $request->get_param( 'product' );
        $ip      = $request->get_param( 'ip' )      ?? '';

        // Duplicate check — one active session per email+product
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . CCDEMO_TABLE .
            " WHERE email = %s AND product = %s AND status IN ('pending','active') AND expires_at > %s LIMIT 1",
            $email, $product, current_time( 'mysql' )
        ) );

        if ( $existing ) {
            return new WP_Error( 'duplicate_session', 'An active demo session already exists for this email and product.', [ 'status' => 409 ] );
        }

        // Create temp WP user
        $expiry_days = (int) get_option( 'ccdemo_expiry_days', 2 );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + $expiry_days * DAY_IN_SECONDS );

        $base_username = 'demo_' . strtolower( preg_replace( '/[^a-z0-9]/i', '', $name ) );
        $username      = $base_username . '_' . bin2hex( random_bytes( 4 ) );
        $password      = wp_generate_password( 24, true, true );

        $user_id = wp_create_user( $username, $password, $username . '@demo.ccdemo.internal' );

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'user_creation_failed', 'Could not create demo user.', [ 'status' => 500 ] );
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'ccdemo_user' );

        update_user_meta( $user_id, '_ccdemo_expires_at', $expires_at );
        update_user_meta( $user_id, '_ccdemo_product',    $product );
        update_user_meta( $user_id, '_ccdemo_real_email', sanitize_email( $email ) );
        update_user_meta( $user_id, '_ccdemo_real_name',  sanitize_text_field( $name ) );

        // Generate cryptographic token
        $token = bin2hex( random_bytes( 32 ) );

        $session_id = CCDemo_DB::insert_session( [
            'name'       => $name,
            'email'      => $email,
            'company'    => $company,
            'phone'      => $phone,
            'product'    => $product,
            'token'      => $token,
            'user_id'    => $user_id,
            'expires_at' => $expires_at,
            'ip'         => $ip,
        ] );

        if ( ! $session_id ) {
            wp_delete_user( $user_id );
            return new WP_Error( 'session_creation_failed', 'Could not persist demo session.', [ 'status' => 500 ] );
        }

        $magic_link = add_query_arg( 'ccdemo_token', $token, home_url( '/' ) );

        return new WP_REST_Response( [
            'success'     => true,
            'token'       => $token,
            'magic_link'  => $magic_link,
            'expires_at'  => $expires_at,
            'session_id'  => $session_id,
        ], 201 );
    }

    /* ------------------------------------------------------------------
     * GET /ccdemo/v1/health
     * ------------------------------------------------------------------ */

    public function health_check( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $table_exists = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}" . CCDEMO_TABLE . "'"
        );

        return new WP_REST_Response( [
            'status'       => 'ok',
            'version'      => CCDEMO_VERSION,
            'mode'         => 'demo',
            'db_table'     => $table_exists,
            'server_time'  => gmdate( 'c' ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Argument schema for create-session
     * ------------------------------------------------------------------ */

    private function create_session_args(): array {
        return [
            'name'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'minLength' => 2, 'maxLength' => 100 ],
            'email'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_email',      'format' => 'email' ],
            'product' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
            'company' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'phone'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'ip'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
        ];
    }

    /* ------------------------------------------------------------------
     * Audit logging
     * ------------------------------------------------------------------ */

    private function audit_log( string $event, string $ip ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( '[CCDemo][AUDIT] %s from %s at %s', $event, $ip, gmdate( 'c' ) ) );
        }
    }
}
