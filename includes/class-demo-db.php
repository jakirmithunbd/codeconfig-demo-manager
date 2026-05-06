<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_DB {

    /**
     * Called on plugin activation — creates the sessions table.
     */
    public static function install(): void {
        global $wpdb;

        $table   = $wpdb->prefix . CCDEMO_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(100)    NOT NULL DEFAULT '',
            email         VARCHAR(191)    NOT NULL DEFAULT '',
            company       VARCHAR(150)    NOT NULL DEFAULT '',
            phone         VARCHAR(30)     NOT NULL DEFAULT '',
            product       VARCHAR(100)    NOT NULL DEFAULT '',
            token         VARCHAR(64)     NOT NULL DEFAULT '',
            user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
            ip_address    VARCHAR(45)     NOT NULL DEFAULT '',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at    DATETIME        NOT NULL,
            accessed_at   DATETIME                 DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   token (token),
            KEY          email (email),
            KEY          status (status),
            KEY          expires_at (expires_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'ccdemo_db_version', CCDEMO_VERSION );
    }

    /**
     * Insert a new demo session row and return its ID (or false on failure).
     */
    public static function insert_session( array $data ): int|false {
        global $wpdb;

        $inserted = $wpdb->insert(
            $wpdb->prefix . CCDEMO_TABLE,
            [
                'name'       => sanitize_text_field( $data['name'] ),
                'email'      => sanitize_email( $data['email'] ),
                'company'    => sanitize_text_field( $data['company'] ?? '' ),
                'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
                'product'    => sanitize_text_field( $data['product'] ),
                'token'      => $data['token'],
                'user_id'    => (int) ( $data['user_id'] ?? 0 ),
                'status'     => 'pending',
                'ip_address' => sanitize_text_field( $data['ip'] ?? '' ),
                'created_at' => current_time( 'mysql' ),
                'expires_at' => $data['expires_at'],
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get a session by token.
     */
    public static function get_session_by_token( string $token ): object|null {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE token = %s LIMIT 1",
            $token
        ) );
    }

    /**
     * Update a session row.
     */
    public static function update_session( int $id, array $data ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . CCDEMO_TABLE,
            $data,
            [ 'id' => $id ]
        );
    }

    /**
     * Return all expired sessions that still have an active WP user.
     */
    public static function get_expired_sessions(): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE .
            " WHERE expires_at < %s AND status IN ('active','pending') AND user_id > 0",
            current_time( 'mysql' )
        ) );
    }

    /**
     * Return all sessions for admin list (latest first).
     */
    public static function get_all_sessions( int $limit = 200 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Delete a single session row.
     */
    public static function delete_session( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . CCDEMO_TABLE, [ 'id' => $id ], [ '%d' ] );
    }
}
