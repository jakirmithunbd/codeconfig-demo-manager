<?php
/**
 * REST API Client — runs on codeconfig.dev. Compatible with PHP 7.4+.
 *
 * Use the factory method for per-product routing:
 *   $client = CCDemo_API_Client::for_product('google-drive');
 *   $result = $client->create_session([...]);
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_API_Client {

    private $base_url;
    private $api_key;
    private $timeout = 10;

    public function __construct( $demo_site_url, $api_key ) {
        $this->base_url = trailingslashit( $demo_site_url ) . 'wp-json/ccdemo/v1';
        $this->api_key  = (string) $api_key;
    }

    /**
     * Factory: resolve demo URL + API key for a product slug.
     *
     * @param  string $product_slug
     * @return self
     */
    public static function for_product( $product_slug ) {
        return new self(
            CCDemo_Products::demo_url_for( $product_slug ),
            CCDemo_Products::api_key_for( $product_slug )
        );
    }

    /* ------------------------------------------------------------------
     * Create session
     * ------------------------------------------------------------------ */

    /**
     * @return array{success:bool, ...}
     */
    public function create_session( array $data ) {
        // Guard: URL must be configured and valid
        $site_url = $this->site_url();
        if ( empty( $site_url ) || ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
            return $this->err(
                'Demo site URL is not configured for this product. ' .
                'Please go to Demo Manager → Settings → Products and add the demo subdomain URL.'
            );
        }

        if ( empty( $this->api_key ) ) {
            return $this->err( 'API key is not configured. Please set it in Demo Manager → Settings.' );
        }

        $response = $this->post( '/create-session', array(
            'name'    => $data['name'],
            'email'   => $data['email'],
            'company' => isset( $data['company'] ) ? $data['company'] : '',
            'phone'   => isset( $data['phone'] )   ? $data['phone']   : '',
            'product' => $data['product'],
            'ip'      => isset( $data['ip'] )      ? $data['ip']      : '',
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            $this->log( 'create_session', 'WP_Error: ' . $msg );
            return $this->err( 'Could not reach the demo server (' . esc_html( $site_url ) . '). Error: ' . $msg );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = (array) json_decode( $raw, true );

        // Log non-201 responses for debugging
        if ( $code !== 201 ) {
            $this->log( 'create_session', "HTTP {$code} from {$site_url} — " . substr( $raw, 0, 200 ) );
        }

        if ( $code === 409 ) {
            return $this->err( 'An active demo for this product already exists on your email. Check your inbox.' );
        }

        if ( $code !== 201 || empty( $body['magic_link'] ) ) {
            $msg = ! empty( $body['message'] ) ? $body['message'] : "Demo server returned HTTP {$code}. Please try again.";
            return $this->err( $msg );
        }

        return array(
            'success'    => true,
            'token'      => $body['token'],
            'magic_link' => $body['magic_link'],
            'expires_at' => $body['expires_at'],
            'session_id' => isset( $body['session_id'] ) ? (int) $body['session_id'] : 0,
            'demo_url'   => $site_url,
        );
    }

    /**
     * Health check — cached 5 min per demo site URL.
     *
     * @return array
     */
    public function health_check() {
        $site_url  = $this->site_url();
        $cache_key = 'ccdemo_health_' . md5( $this->base_url );
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        if ( empty( $site_url ) || ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
            $result = array( 'ok' => false, 'error' => 'Demo site URL not configured', 'url' => $site_url );
            set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
            return $result;
        }

        $response = $this->get( '/health' );

        if ( is_wp_error( $response ) ) {
            $result = array( 'ok' => false, 'error' => $response->get_error_message(), 'url' => $site_url );
        } else {
            $code   = (int) wp_remote_retrieve_response_code( $response );
            $body   = (array) json_decode( wp_remote_retrieve_body( $response ), true );
            $result = ( $code === 200 && isset( $body['status'] ) && $body['status'] === 'ok' )
                ? array( 'ok' => true, 'version' => isset( $body['version'] ) ? $body['version'] : '', 'url' => $site_url )
                : array( 'ok' => false, 'error' => "HTTP {$code}", 'url' => $site_url );
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * Check every configured demo site at once.
     *
     * @return array<string, array>
     */
    public static function check_all_sites() {
        $products = CCDemo_Products::all();
        $results  = array();
        $seen     = array();

        foreach ( $products as $slug => $product ) {
            $url = CCDemo_Products::demo_url_for( $slug );

            if ( ! empty( $url ) && isset( $seen[ $url ] ) ) {
                // Two products on the same install — copy result, update label
                $results[ $slug ] = array_merge( $results[ $seen[ $url ] ], array( 'label' => $product['label'] ) );
                continue;
            }

            if ( ! empty( $url ) ) {
                $seen[ $url ] = $slug;
            }

            $client           = new self( $url, CCDemo_Products::api_key_for( $slug ) );
            $health           = $client->health_check();
            $results[ $slug ] = array_merge( $health, array( 'label' => $product['label'] ) );
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     * HTTP helpers
     * ------------------------------------------------------------------ */

    private function post( $path, array $body ) {
        return wp_remote_post(
            $this->base_url . $path,
            array(
                'timeout'     => $this->timeout,
                'redirection' => 0,
                'headers'     => $this->headers(),
                'body'        => wp_json_encode( $body ),
                'data_format' => 'body',
                'sslverify'   => $this->ssl_verify(),
            )
        );
    }

    private function get( $path ) {
        return wp_remote_get(
            $this->base_url . $path,
            array(
                'timeout'   => $this->timeout,
                'headers'   => $this->headers(),
                'sslverify' => $this->ssl_verify(),
            )
        );
    }

    private function headers() {
        return array(
            'Authorization'      => 'Bearer ' . $this->api_key,
            'Content-Type'       => 'application/json',
            'X-CCDemo-Timestamp' => (string) time(),
            'X-CCDemo-Nonce'     => bin2hex( random_bytes( 16 ) ),
            'X-CCDemo-Source'    => home_url(),
        );
    }

    private function site_url() {
        return str_replace( '/wp-json/ccdemo/v1', '', $this->base_url );
    }

    private function ssl_verify() {
        return ! ( defined( 'WP_ENV' ) && WP_ENV === 'development' );
    }

    private function err( $message ) {
        return array( 'success' => false, 'error' => $message );
    }

    private function log( $ctx, $msg ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( "[CCDemo][{$ctx}] {$msg}" );
        }
    }
}
