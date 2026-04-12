<?php
/**
 * Jobs Handlers — feed kinds.
 *
 * Registers two job kinds wrapping the existing feed import functions:
 *   - csv_feed     → gh_csv_run_feed()
 *   - config_feed  → gh_fc_run() (config-engine feed)
 *
 * Both are one-shot today (no cursoring). The runner's chunking contract is
 * available for when the underlying functions grow cursor support — handlers
 * will then be able to read $context['cursor'] and return status=continue.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gh_jobs_register', function () {

    gh_jobs_register_kind( 'csv_feed', [
        'label'       => 'CSV Feed Import',
        'description' => 'Esegue un\'importazione da un CSV Feed configurato (feed-csv.php).',
        'params'      => [
            'feed_id' => [
                'type'     => 'string',
                'label'    => 'CSV Feed ID',
                'required' => true,
            ],
            'create_new' => [
                'type'    => 'bool',
                'label'   => 'Crea nuovi prodotti',
                'default' => true,
            ],
            'update_existing' => [
                'type'    => 'bool',
                'label'   => 'Aggiorna prodotti esistenti',
                'default' => true,
            ],
            'sideload_images' => [
                'type'    => 'bool',
                'label'   => 'Scarica immagini (sideload)',
                'default' => false,
            ],
        ],
        'handler'     => 'gh_jobs_handler_csv_feed',
    ] );

    gh_jobs_register_kind( 'config_feed', [
        'label'       => 'Config-Engine Feed Import',
        'description' => 'Esegue un\'importazione da un config-engine feed (feed-config-engine.php).',
        'params'      => [
            'config_id' => [
                'type'     => 'string',
                'label'    => 'Config ID',
                'required' => true,
            ],
            'source_type' => [
                'type'    => 'enum',
                'label'   => 'Tipo sorgente',
                'options' => [ 'url', 'path' ],
                'default' => 'url',
            ],
            'source_url' => [
                'type'  => 'string',
                'label' => 'URL sorgente (se tipo=url)',
            ],
            'source_path' => [
                'type'  => 'string',
                'label' => 'Path locale (se tipo=path)',
            ],
            'create_new' => [
                'type'    => 'bool',
                'label'   => 'Crea nuovi prodotti',
                'default' => true,
            ],
            'update_existing' => [
                'type'    => 'bool',
                'label'   => 'Aggiorna prodotti esistenti',
                'default' => true,
            ],
            'sideload_images' => [
                'type'    => 'bool',
                'label'   => 'Scarica immagini (sideload)',
                'default' => false,
            ],
        ],
        'handler'     => 'gh_jobs_handler_config_feed',
    ] );

}, 5 );

/**
 * Handler: csv_feed.
 */
function gh_jobs_handler_csv_feed( array $job, array $context ): array {
    $params  = $job['params'] ?? [];
    $feed_id = (string) ( $params['feed_id'] ?? '' );

    if ( $feed_id === '' ) {
        return [ 'status' => 'error', 'error' => 'feed_id mancante.' ];
    }

    if ( ! function_exists( 'gh_csv_run_feed' ) ) {
        return [ 'status' => 'error', 'error' => 'gh_csv_run_feed() non disponibile.' ];
    }

    $opts = [
        'create_new'      => (bool) ( $params['create_new']      ?? true ),
        'update_existing' => (bool) ( $params['update_existing'] ?? true ),
        'sideload_images' => (bool) ( $params['sideload_images'] ?? false ),
    ];

    $result = gh_csv_run_feed( $feed_id, $opts );

    if ( is_wp_error( $result ) ) {
        return [ 'status' => 'error', 'error' => $result->get_error_message() ];
    }

    return [
        'status'  => 'done',
        'summary' => is_array( $result ) ? ( $result['summary'] ?? $result ) : [ 'result' => $result ],
    ];
}

/**
 * Handler: config_feed.
 *
 * This handler duplicates the orchestration logic previously in
 * gh_sched_run_config() because the underlying functions are low-level
 * primitives (load config, fetch rows, normalize, transform, diff, create/update).
 */
function gh_jobs_handler_config_feed( array $job, array $context ): array {
    $params      = $job['params'] ?? [];
    $config_id   = (string) ( $params['config_id']   ?? '' );
    $source_type = (string) ( $params['source_type'] ?? 'url' );
    $source_url  = (string) ( $params['source_url']  ?? '' );
    $source_path = (string) ( $params['source_path'] ?? '' );

    if ( $config_id === '' ) {
        return [ 'status' => 'error', 'error' => 'config_id mancante.' ];
    }

    if ( ! function_exists( 'gh_fc_load_config' ) ) {
        return [ 'status' => 'error', 'error' => 'Config engine non disponibile.' ];
    }

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) {
        return [ 'status' => 'error', 'error' => "Config non trovato: {$config_id}" ];
    }

    // Fetch source rows
    if ( $source_type === 'url' ) {
        if ( $source_url === '' ) {
            return [ 'status' => 'error', 'error' => 'URL sorgente mancante.' ];
        }
        $response = rp_rc_request( [ 'url' => $source_url, 'method' => 'GET', 'timeout' => 120 ] );
        if ( ! empty( $response['error'] ) ) {
            return [ 'status' => 'error', 'error' => (string) $response['error'] ];
        }
        if ( $response['status'] < 200 || $response['status'] >= 300 ) {
            return [ 'status' => 'error', 'error' => "HTTP {$response['status']}" ];
        }
        $rows = rp_rc_parse_csv( $response['body'] );
    } else {
        if ( $source_path === '' ) {
            return [ 'status' => 'error', 'error' => 'Path sorgente mancante.' ];
        }
        $rows = gh_csv_read_file( $source_path );
    }

    if ( is_wp_error( $rows ) ) {
        return [ 'status' => 'error', 'error' => $rows->get_error_message() ];
    }
    if ( empty( $rows ) ) {
        return [ 'status' => 'error', 'error' => 'CSV vuoto.' ];
    }

    $products     = gh_fc_normalize( $rows, $config );
    $woo_products = gh_fc_transform_all( $products, $config );
    $diff         = gh_csv_diff( $woo_products );

    $create   = (bool) ( $params['create_new']      ?? true );
    $update   = (bool) ( $params['update_existing'] ?? true );
    $sideload = (bool) ( $params['sideload_images'] ?? false );

    // NOTE: both csv_feed and config_feed run one-shot in commit A. The
    // runner's chunking contract is fully wired; these two handlers don't
    // yield because resuming would require re-fetching the source (no free
    // lunch without cursor support in the underlying primitives). Long feeds
    // should set max_runtime/tick_budget high enough to complete in one tick.
    $results = [];

    if ( $create ) {
        foreach ( $diff['new'] as $p ) {
            $results[] = gh_fc_create_product( $p, $sideload );
        }
    }
    if ( $update ) {
        foreach ( $diff['update'] as $p ) {
            $results[] = gh_csv_update_product( $p );
        }
    }

    $created = count( array_filter( $results, fn( $r ) => ( $r['action'] ?? '' ) === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => ( $r['action'] ?? '' ) === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => ( $r['action'] ?? '' ) === 'error' ) );

    return [
        'status'  => 'done',
        'summary' => [
            'rows_read' => count( $rows ),
            'created'   => $created,
            'updated'   => $updated,
            'errors'    => $errors,
        ],
    ];
}
