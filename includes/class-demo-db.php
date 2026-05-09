<?php
/**
 * Database — runs on demo subdomains. Compatible with PHP 7.4+.
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_DB {

    const TOKEN_CACHE_TTL = 60;

    /* ------------------------------------------------------------------
     * Installation / auto-repair
     * ------------------------------------------------------------------ */

    /**
     * Create (or upgrade) the sessions table.
     * Safe to call on every boot — dbDelta is idempotent.
     */
    public static function install() {
        global $wpdb;

        $table   = $wpdb->prefix . CCDEMO_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
            name         VARCHAR(100)        NOT NULL DEFAULT '',
            email        VARCHAR(191)        NOT NULL DEFAULT '',
            company      VARCHAR(150)        NOT NULL DEFAULT '',
            phone        VARCHAR(30)         NOT NULL DEFAULT '',
            product      VARCHAR(100)        NOT NULL DEFAULT '',
            token        CHAR(64)            NOT NULL DEFAULT '',
            user_id      BIGINT UNSIGNED     NOT NULL DEFAULT 0,
            status       VARCHAR(20)         NOT NULL DEFAULT 'pending',
            ip_address   VARCHAR(45)         NOT NULL DEFAULT '',
            created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at   DATETIME            NOT NULL,
            accessed_at  DATETIME                     DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_token                (token),
            KEY          idx_email_product_status (email(40), product(40), status),
            KEY          idx_expires_status       (expires_at, status),
            KEY          idx_user_id              (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'ccdemo_db_version', CCDEMO_VERSION );
    }

    /* ------------------------------------------------------------------
     * Write operations
     * ------------------------------------------------------------------ */

    /**
     * @return int|false Inserted row ID or false on failure.
     */
    public static function insert_session( array $data ) {
        global $wpdb;

        $inserted = $wpdb->insert(
            $wpdb->prefix . CCDEMO_TABLE,
            array(
                'name'       => sanitize_text_field( $data['name'] ),
                'email'      => sanitize_email( $data['email'] ),
                'company'    => sanitize_text_field( isset( $data['company'] ) ? $data['company'] : '' ),
                'phone'      => sanitize_text_field( isset( $data['phone'] )   ? $data['phone']   : '' ),
                'product'    => sanitize_key( $data['product'] ),
                'token'      => $data['token'],
                'user_id'    => (int) ( isset( $data['user_id'] ) ? $data['user_id'] : 0 ),
                'status'     => 'pending',
                'ip_address' => sanitize_text_field( isset( $data['ip'] ) ? $data['ip'] : '' ),
                'created_at' => current_time( 'mysql' ),
                'expires_at' => $data['expires_at'],
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public static function update_session( $id, array $data ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . CCDEMO_TABLE, $data, array( 'id' => (int) $id ) );

        if ( isset( $data['token'] ) ) {
            delete_transient( self::token_cache_key( $data['token'] ) );
        }
    }

    public static function delete_session( $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . CCDEMO_TABLE, array( 'id' => (int) $id ), array( '%d' ) );
    }

    /* ------------------------------------------------------------------
     * Read operations
     * ------------------------------------------------------------------ */

    /**
     * @return object|null
     */
    public static function get_session_by_token( $token ) {
        $cache_key = self::token_cache_key( $token );
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached ? $cached : null;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE token = %s LIMIT 1",
            $token
        ) );

        set_transient( $cache_key, $row ? $row : '', self::TOKEN_CACHE_TTL );
        return $row;
    }

    /**
     * @return array
     */
    public static function get_expired_sessions() {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id FROM {$wpdb->prefix}" . CCDEMO_TABLE .
            " WHERE expires_at < %s AND status IN ('pending','active') AND user_id > 0",
            current_time( 'mysql' )
        ) );
    }

    /**
     * @return array
     */
    public static function get_all_sessions( $limit = 200 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " ORDER BY created_at DESC LIMIT %d",
            (int) $limit
        ) );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function token_cache_key( $token ) {
        return 'ccdemo_tok_' . hash( 'sha256', $token );
    }
}
