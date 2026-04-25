<?php
/**
 * Jobs Handler — catalog_snapshot.
 *
 * Registers a job kind that captures a daily catalog snapshot for the diff
 * visualization. One-shot per tick (no cursoring) — for ~hundreds of products
 * the capture completes within tick_budget. Larger catalogs should bump
 * tick_budget on the job record.
 *
 * The job is auto-installed at plugin activation if no snapshot job exists
 * yet (see gh_history_install_default_job()).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gh_jobs_register', function () {
    gh_jobs_register_kind( 'catalog_snapshot', [
        'label'       => 'Catalog Snapshot (daily)',
        'description' => "Cattura uno snapshot di tutti i prodotti del catalogo per la diff visualization. Una riga per data — una seconda esecuzione nello stesso giorno sostituisce la precedente. Retention 30 giorni.",
        'params'      => [
            'trigger_label' => [
                'type'    => 'string',
                'label'   => 'Trigger label (audit)',
                'default' => 'cron',
            ],
        ],
        'handler'     => 'gh_jobs_handler_catalog_snapshot',
    ] );
}, 5 );

function gh_jobs_handler_catalog_snapshot( array $job, array $context ): array {
    if ( ! function_exists( 'gh_history_capture' ) ) {
        return [ 'status' => 'error', 'error' => 'gh_history_capture() non disponibile.' ];
    }

    $params  = $job['params'] ?? [];
    $trigger = (string) ( $params['trigger_label'] ?? 'cron' );

    $result = gh_history_capture( $trigger );

    if ( isset( $result['error'] ) ) {
        return [ 'status' => 'error', 'error' => (string) $result['error'] ];
    }

    return [
        'status'  => 'done',
        'summary' => [
            'snapshot_id'   => $result['snapshot_id']   ?? 0,
            'snapshot_date' => $result['snapshot_date'] ?? '',
            'product_count' => $result['product_count'] ?? 0,
            'duration_ms'   => $result['duration_ms']   ?? 0,
        ],
    ];
}

/**
 * Installs a default daily snapshot job at activation if no catalog_snapshot
 * job exists yet. Idempotent — re-activations don't duplicate.
 *
 * Schedule: 03:15 every day (off-peak for most timezones the plugin runs in).
 */
function gh_history_install_default_job(): void {
    if ( ! function_exists( 'gh_jobs_get_all' ) || ! function_exists( 'gh_jobs_save' ) ) return;

    foreach ( gh_jobs_get_all() as $j ) {
        if ( ( $j['kind'] ?? '' ) === 'catalog_snapshot' ) return;
    }

    gh_jobs_save( [
        'label'       => 'Catalog snapshot — daily',
        'kind'        => 'catalog_snapshot',
        'cron'        => '15 3 * * *',
        'enabled'     => true,
        'tick_budget' => 60,
        'max_runtime' => 1800,
        'params'      => [ 'trigger_label' => 'cron' ],
    ] );
}
