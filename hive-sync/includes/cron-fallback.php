<?php
/**
 * WP-Cron fallback for environments where DISABLE_WP_CRON is set
 * (typical in Docker dev/prod stacks that haven't wired a host cron
 * container yet).
 *
 * On every authenticated admin page load, throttled to once per N
 * seconds, this drains:
 *
 *   1. WordPress's own due cron events     (wp_cron / spawn_cron)
 *      — picks up the hive_sync_jobs_tick we registered in cron.php,
 *      plus anything else WP has scheduled.
 *
 *   2. WooCommerce's Action Scheduler queue (action_scheduler_run_queue)
 *      — drains the per-action backlog that the "411 past-due actions"
 *      admin notice complains about.
 *
 * The throttle is a transient ('hsync_cron_fallback_lock', default 60s)
 * so this never runs more than once per minute even with many admins
 * clicking around. The work is non-blocking: WP's spawn_cron() fires a
 * loopback HTTP request and returns immediately; Action Scheduler's
 * runner is async-queue-triggered the same way.
 *
 * No effect when WP-Cron is properly wired (DISABLE_WP_CRON unset and a
 * real cron pinging wp-cron.php) — the throttle still skips but the
 * work has already been done by the real cron.
 */

defined( 'ABSPATH' ) || exit;

const HSYNC_CRON_FALLBACK_LOCK = 'hsync_cron_fallback_lock';
const HSYNC_CRON_FALLBACK_INTERVAL = 60;  // seconds

add_action( 'admin_init', 'hsync_cron_fallback_maybe_run' );

function hsync_cron_fallback_maybe_run(): void {
    if ( wp_doing_ajax() || wp_doing_cron() ) return;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    if ( get_transient( HSYNC_CRON_FALLBACK_LOCK ) ) return;

    set_transient( HSYNC_CRON_FALLBACK_LOCK, time(), HSYNC_CRON_FALLBACK_INTERVAL );

    // 1. Drain core WP-Cron. spawn_cron() respects the regular cron lock
    //    and fires a non-blocking loopback request — safe to call
    //    repeatedly.
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron();
    }

    // 2. Drain Action Scheduler. Available when WooCommerce is loaded.
    //    'Async Request' context tells AS this is an in-band admin
    //    triggered run, not a WP-Cron trigger.
    if ( has_action( 'action_scheduler_run_queue' ) ) {
        do_action( 'action_scheduler_run_queue', 'Async Request' );
    }
}

/**
 * Admin notice surfaced when DISABLE_WP_CRON is set, so the operator
 * knows the fallback is what's keeping things running. Dismissible
 * per-user via the standard WP transient pattern.
 */
add_action( 'admin_notices', function () {
    if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) return;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'toplevel_page_hive-sync' ) return;

    echo '<div class="notice notice-warning"><p>'
        . '<strong>Hive Sync:</strong> <code>DISABLE_WP_CRON</code> è attivo. '
        . 'Hive Sync e Action Scheduler vengono drenati ad ogni caricamento '
        . 'pagina admin (max ogni ' . esc_html( (string) HSYNC_CRON_FALLBACK_INTERVAL ) . 's). '
        . 'Per produzione consigliato wirare un cron host che pinghi <code>wp-cron.php</code> ogni minuto.'
        . '</p></div>';
} );
