<?php
/**
 * Product registry — shared by both form and demo sites.
 *
 * Each product entry:
 *   slug       string   URL-safe identifier, e.g. "google-drive"
 *   label      string   Human label, e.g. "Integration for Google Drive"
 *   demo_url   string   The demo subdomain, e.g. "https://igd.codeconfig.dev"
 *   api_key    string   Optional per-site key override; falls back to global key
 *
 * Storage format in the textarea (one product per line):
 *   slug|Label|https://demo-subdomain.codeconfig.dev
 *   slug|Label|https://demo-subdomain.codeconfig.dev|optional_api_key_override
 *
 * Examples:
 *   google-drive|Integration for Google Drive|https://igd.codeconfig.dev
 *   dropbox|Integration for Dropbox|https://idb.codeconfig.dev
 *   onedrive|Integration for OneDrive|https://iod.codeconfig.dev
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Products {

    private const CACHE_KEY = 'ccdemo_products_v2';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /* ------------------------------------------------------------------
     * Read
     * ------------------------------------------------------------------ */

    /**
     * Return the full product map, keyed by slug.
     *
     * @return array<string, array{label:string, demo_url:string, api_key:string}>
     */
    public static function all(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        $stored = get_option( 'ccdemo_products_v2', [] );

        if ( empty( $stored ) ) {
            $stored = self::defaults();
        }

        $products = apply_filters( 'ccdemo_products', $stored );
        set_transient( self::CACHE_KEY, $products, self::CACHE_TTL );
        return $products;
    }

    /**
     * Return [ slug => label ] — for the form's product buttons and validation.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return array_map( static fn( $p ) => $p['label'], self::all() );
    }

    /**
     * Look up a single product. Returns null if not found.
     *
     * @return array{label:string, demo_url:string, api_key:string}|null
     */
    public static function get( string $slug ): ?array {
        return self::all()[ $slug ] ?? null;
    }

    /**
     * The demo site URL for a given product slug.
     * Falls back to the global demo site URL if the product has none configured.
     */
    public static function demo_url_for( string $slug ): string {
        $product = self::get( $slug );
        if ( $product && ! empty( $product['demo_url'] ) ) {
            return $product['demo_url'];
        }
        // Fallback to the old single-site URL so existing setups keep working
        return (string) get_option( 'ccdemo_demo_site_url', '' );
    }

    /**
     * The API key for a given product slug.
     * Falls back to the global API key if no per-product override is set.
     */
    public static function api_key_for( string $slug ): string {
        $product = self::get( $slug );
        if ( $product && ! empty( $product['api_key'] ) ) {
            return $product['api_key'];
        }
        return (string) get_option( 'ccdemo_api_key', '' );
    }

    /* ------------------------------------------------------------------
     * Parse / sanitise a raw textarea string into the structured array
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{label:string, demo_url:string, api_key:string}>
     */
    public static function parse_raw( string $raw ): array {
        $lines    = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        $products = [];

        foreach ( $lines as $line ) {
            if ( str_starts_with( $line, '#' ) ) {
                continue; // comment lines
            }

            $parts = array_map( 'trim', explode( '|', $line ) );
            $slug  = sanitize_key( $parts[0] ?? '' );
            $label = sanitize_text_field( $parts[1] ?? '' );
            $url   = esc_url_raw( $parts[2] ?? '' );
            $key   = sanitize_text_field( $parts[3] ?? '' );

            if ( ! $slug || ! $label ) {
                continue;
            }

            $products[ $slug ] = [
                'label'    => $label,
                'demo_url' => $url,
                'api_key'  => $key,
            ];
        }

        return $products;
    }

    /**
     * Serialise the structured array back to raw textarea format.
     */
    public static function to_raw( array $products ): string {
        $lines = [];
        foreach ( $products as $slug => $p ) {
            $parts = [ $slug, $p['label'], $p['demo_url'] ];
            if ( ! empty( $p['api_key'] ) ) {
                $parts[] = $p['api_key'];
            }
            $lines[] = implode( '|', $parts );
        }
        return implode( "\n", $lines );
    }

    /* ------------------------------------------------------------------
     * Cache busting
     * ------------------------------------------------------------------ */

    public static function bust_cache(): void {
        delete_transient( self::CACHE_KEY );
        // Also clear the legacy v1 cache key used by CCDemo_Form::get_products()
        delete_transient( 'ccdemo_products_cache' );
    }

    /* ------------------------------------------------------------------
     * Defaults
     * ------------------------------------------------------------------ */

    private static function defaults(): array {
        return [
            'google-drive' => [
                'label'    => 'Integration for Google Drive',
                'demo_url' => 'https://igd.codeconfig.dev',
                'api_key'  => '',
            ],
            'dropbox' => [
                'label'    => 'Integration for Dropbox',
                'demo_url' => 'https://idb.codeconfig.dev',
                'api_key'  => '',
            ],
            'onedrive' => [
                'label'    => 'Integration for OneDrive',
                'demo_url' => 'https://iod.codeconfig.dev',
                'api_key'  => '',
            ],
            'sharepoint' => [
                'label'    => 'Integration for SharePoint',
                'demo_url' => 'https://isp.codeconfig.dev',
                'api_key'  => '',
            ],
        ];
    }
}
