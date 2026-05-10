<?php
/**
 * WP-Cron cleanup — runs on demo subdomains. Compatible with PHP 7.4+.
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Cron {

    const HOOK = 'ccdemo_cleanup';

    /* ------------------------------------------------------------------
     * Lifecycle
     * ------------------------------------------------------------------ */

    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK );
        }
    }

    /* ------------------------------------------------------------------
     * Cleanup routine
     * ------------------------------------------------------------------ */

    public static function cleanup_expired() {
        $sessions = CCDemo_DB::get_expired_sessions();
        $count    = 0;

        foreach ( $sessions as $session ) {
            self::delete_demo_user( (int) $session->user_id );
            CCDemo_DB::update_session( (int) $session->id, array(
                'status'  => 'expired',
                'user_id' => 0,
            ) );
            $count++;
        }

        if ( $count ) {
            self::log( "Cleanup: {$count} expired session(s) processed." );
            do_action( 'ccdemo_after_cleanup', $count );
        }
    }

    /* ------------------------------------------------------------------
     * Delete a single demo WP user
     *
     * @param  int  $user_id
     * @return bool
     * ------------------------------------------------------------------ */

    public static function delete_demo_user( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return false;
        }

        // Safety guard — only delete users created by the demo system
        if ( ! get_user_meta( $user_id, '_ccdemo_expires_at', true ) ) {
            self::log( "SKIPPED user #{$user_id}: not a demo user." );
            return false;
        }

        $reassign_to = (int) get_option( 'ccdemo_reassign_user', 1 );

        // Destroy any active auth sessions before deleting the account
        WP_Session_Tokens::get_instance( $user_id )->destroy_all();

        wp_delete_user( $user_id, $reassign_to );
        self::log( "Deleted demo user #{$user_id}." );

        return true;
    }

    /* ------------------------------------------------------------------
     * Manual cleanup (admin button)
     *
     * @return int Number of sessions cleaned up.
     * ------------------------------------------------------------------ */

    public static function run_manual_cleanup() {
        $sessions = CCDemo_DB::get_expired_sessions();
        $count    = 0;

        foreach ( $sessions as $session ) {
            self::delete_demo_user( (int) $session->user_id );
            CCDemo_DB::update_session( (int) $session->id, array(
                'status'  => 'expired',
                'user_id' => 0,
            ) );
            $count++;
        }

        return $count;
    }

    /**
     * Delete a specific session + user immediately (admin Delete button).
     *
     * @param int $session_id
     */
    public static function delete_session_now( $session_id ) {
        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id FROM {$wpdb->prefix}" . CCDEMO_TABLE . " WHERE id = %d LIMIT 1",
            (int) $session_id
        ) );

        if ( ! $session ) {
            return;
        }

        if ( $session->user_id ) {
            self::delete_demo_user( (int) $session->user_id );
        }

        CCDemo_DB::delete_session( (int) $session->id );
    }

    /* ------------------------------------------------------------------
     * Logging
     * ------------------------------------------------------------------ */

    private static function log( $msg ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[CCDemo][Cron] ' . $msg );
        }
    }
}

// Hook is registered at file-load time so it fires on any WP request, not just
// the ones that go through plugins_loaded → CCDemo_Auth etc.
add_action( CCDemo_Cron::HOOK, array( 'CCDemo_Cron', 'cleanup_expired' ) );
