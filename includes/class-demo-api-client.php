<?php
/**
 * REST API Client — runs on codeconfig.dev
 *
 * Calls the demo site (demo.codeconfig.dev) to create sessions.
 * Every request is signed with:
 *   Authorization: Bearer <api_key>
 *   X-CCDemo-Timestamp: <unix_ts>
 *   X-CCDemo-Nonce: <random_hex>
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_API_Client {

    private string $base_url;
    private string $api_key;
    private int    $timeout = 10; // seconds

    public function __construct() {
        $this->base_url = trailingslashit( get_option( 'ccdemo_demo_site_url', '' ) ) . 'wp-json/ccdemo/v1';
        $this->api_key  = (string) get_option( 'ccdemo_api_key', '' );
    }

    /* ------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------ */

    /**
     * Create a new demo session on the remote demo site.
     *
     * @return array{success:bool, token?:string, magic_link?:string, expires_at?:string, error?:string}
     */
    public function create_session( array $data ): array {
        if ( empty( $this->base_url ) || empty( $this->api_key ) ) {
            return $this->err( 'Demo site URL or API key is not configured.' );
        }

        $response = $this->post( '/create-session', [
            'name'    => $data['name'],
            'email'   => $data['email'],
            'company' => $data['company'] ?? '',
            'phone'   => $data['phone']   ?? '',
            'product' => $data['product'],
            'ip'      => $data['ip']      ?? '',
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'create_session', $response->get_error_message() );
            return $this->err( 'Could not reach the demo server. Please try again shortly.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = $this->decode_body( $response );

        if ( $code === 409 ) {
            return $this->err( 'An active demo for this product is already running. Check your email.' );
        }

        if ( $code !== 201 || empty( $body['magic_link'] ) ) {
            $msg = $body['message'] ?? 'Unexpected error from demo server.';
            $this->log_error( 'create_session', "HTTP {$code}: {$msg}" );
            return $this->err( $msg );
        }

        return [
            'success'    => true,
            'token'      => $body['token'],
            'magic_link' => $body['magic_link'],
            'expires_at' => $body['expires_at'],
            'session_id' => $body['session_id'] ?? 0,
        ];
    }

    /**
     * Ping the demo site health endpoint.
     * Cached for 5 minutes to avoid hammering on every settings page load.
     *
     * @return array{ok:bool, version?:string, error?:string}
     */
    public function health_check(): array {
        $cache_key = 'ccdemo_health_' . md5( $this->base_url );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $response = $this->get( '/health' );

        if ( is_wp_error( $response ) ) {
            $result = [ 'ok' => false, 'error' => $response->get_error_message() ];
        } else {
            $code   = wp_remote_retrieve_response_code( $response );
            $body   = $this->decode_body( $response );
            $result = $code === 200 && ( $body['status'] ?? '' ) === 'ok'
                ? [ 'ok' => true, 'version' => $body['version'] ?? '' ]
                : [ 'ok' => false, 'error' => "HTTP {$code}" ];
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /* ------------------------------------------------------------------
     * HTTP helpers
     * ------------------------------------------------------------------ */

    private function post( string $path, array $body ): array|WP_Error {
        return wp_remote_post(
            $this->base_url . $path,
            [
                'timeout'     => $this->timeout,
                'redirection' => 0,
                'headers'     => $this->build_headers(),
                'body'        => wp_json_encode( $body ),
                'data_format' => 'body',
                'sslverify'   => ! ( defined( 'WP_ENV' ) && WP_ENV === 'development' ),
            ]
        );
    }

    private function get( string $path ): array|WP_Error {
        return wp_remote_get(
            $this->base_url . $path,
            [
                'timeout'   => $this->timeout,
                'headers'   => $this->build_headers(),
                'sslverify' => ! ( defined( 'WP_ENV' ) && WP_ENV === 'development' ),
            ]
        );
    }

    /**
     * Build signed request headers.
     */
    private function build_headers(): array {
        return [
            'Authorization'      => 'Bearer ' . $this->api_key,
            'Content-Type'       => 'application/json',
            'X-CCDemo-Timestamp' => (string) time(),
            'X-CCDemo-Nonce'     => bin2hex( random_bytes( 16 ) ),
            'X-CCDemo-Source'    => home_url(),
        ];
    }

    private function decode_body( array $response ): array {
        return (array) json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function err( string $message ): array {
        return [ 'success' => false, 'error' => $message ];
    }

    private function log_error( string $context, string $msg ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( "[CCDemo][{$context}] {$msg}" );
        }
    }
}
