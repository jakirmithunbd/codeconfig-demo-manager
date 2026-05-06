<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_Cron {

    const HOOK = 'ccdemo_cleanup';

    /**
     * Register the WP-Cron event on plugin activation.
     */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
        add_action( self::HOOK, [ self::class, 'cleanup_expired' ] );
    }

    /**
     * Remove the cron event on plugin deactivation.
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /**
     * Main cleanup routine — called hourly by WP-Cron.
     * Finds expired sessions, deletes the temp WP user, and marks the session expired.
     */
    public static function cleanup_expired(): void {
        $sessions = CCDemo_DB::get_expired_sessions();

        foreach ( $sessions as $session ) {
            self::delete_demo_user( (int) $session->user_id );
            CCDemo_DB::update_session( (int) $session->id, [
                'status'  => 'expired',
                'user_id' => 0,
            ] );
        }

        if ( count( $sessions ) ) {
            self::log( sprintf( 'Cleaned up %d expired demo session(s).', count( $sessions ) ) );
        }
    }

    /**
     * Delete a single demo WP user (and all their meta / sessions).
     * Reassigns any content to the admin user so nothing is orphaned.
     */
    public static function delete_demo_user( int $user_id ): void {
        if ( ! $user_id ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        // Double-check the user actually has the demo role before deleting
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return;
        }

        if ( ! in_array( 'ccdemo_user', (array) $user->roles, true ) ) {
            // Safety guard — never delete non-demo users
            self::log( "Skipped user #{$user_id} — not a demo user." );
            return;
        }

        $reassign_to = (int) get_option( 'ccdemo_reassign_user', 1 );
        wp_delete_user( $user_id, $reassign_to );

        self::log( "Deleted demo user #{$user_id}." );
    }

    /**
     * Allow manually triggering cleanup from the admin.
     */
    public static function run_manual_cleanup(): int {
        $sessions = CCDemo_DB::get_expired_sessions();
        foreach ( $sessions as $session ) {
            self::delete_demo_user( (int) $session->user_id );
            CCDemo_DB::update_session( (int) $session->id, [
                'status'  => 'expired',
                'user_id' => 0,
            ] );
        }
        return count( $sessions );
    }

    /**
     * Delete a specific session + its WP user immediately (admin action).
     */
    public static function delete_session_now( int $session_id ): void {
        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE id = %d",
            $session_id
        ) );

        if ( ! $session ) {
            return;
        }

        if ( $session->user_id ) {
            self::delete_demo_user( (int) $session->user_id );
        }

        CCDemo_DB::delete_session( $session_id );
    }

    private static function log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[CCDemo] ' . $message );
        }
    }
}

// Hook the cron callback even after activation (every request that fires cron)
add_action( CCDemo_Cron::HOOK, [ 'CCDemo_Cron', 'cleanup_expired' ] );
