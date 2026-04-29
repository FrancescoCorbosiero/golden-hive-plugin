<?php
/**
 * v2 Workflow tab — preview AJAX bridge.
 *
 * One endpoint, gh_v2_workflow_preview, routes by source capabilities:
 *
 *  - canSelectLocal=true (e.g. WooStoreSource) → query the local catalog
 *    via WC_Product_Query. Search is SQL-side ('s' arg).
 *
 *  - canFetch=true       (e.g. GoldenSneakersSource) → call Source::fetch
 *    with a dry-run Context, cache the FeedItem list in a transient
 *    (15 min, keyed by source_id + config hash). Search + paginate
 *    in-memory via GH\Workflow\Preview\InMemoryPaginator.
 *
 * Selection state lives in the browser (Set in JS) — the server is
 * stateless across preview calls. The pipeline builder (Batch 5c/5d)
 * will serialize the final selection as a Selection value object when
 * the user creates the job.
 *
 * Credential hydration for fetch sources is deferred to Batch 5d (the
 * user types credentials in the form for now). Until then the redacted
 * placeholder pattern is treated as "empty" and fetch will fail with a
 * clear "config invalid" warning.
 */

defined( 'ABSPATH' ) || exit;

const GH_V2_PREVIEW_PER_PAGE  = 50;
const GH_V2_PREVIEW_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

add_action( 'wp_ajax_gh_v2_workflow_preview', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }

    $source_id   = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'source_id' ) : (string) ( $_POST['source_id'] ?? '' );
    $config      = function_exists( 'gh_ajax_json' ) ? gh_ajax_json( 'config' )    : [];
    $search      = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'search' )    : '';
    $page        = function_exists( 'gh_ajax_int'  ) ? max( 1, gh_ajax_int( 'page', 1 ) ) : 1;
    $force_fresh = function_exists( 'gh_ajax_bool' ) ? gh_ajax_bool( 'force' )     : ! empty( $_POST['force'] );

    if ( $source_id === '' ) {
        wp_send_json_error( [ 'message' => 'source_id mancante' ], 400 );
    }
    $source = \GH\Core\Bootstrap::$sources->get( $source_id );
    if ( ! $source ) {
        wp_send_json_error( [ 'message' => "Sorgente non registrata: {$source_id}" ], 404 );
    }

    $caps = $source->capabilities();
    if ( $caps->canSelectLocal ) {
        wp_send_json_success( gh_v2_preview_local( $search, $page, GH_V2_PREVIEW_PER_PAGE ) );
    }
    if ( $caps->canFetch ) {
        wp_send_json_success( gh_v2_preview_fetch( $source, $config, $search, $page, GH_V2_PREVIEW_PER_PAGE, $force_fresh ) );
    }

    wp_send_json_success( [
        'items'    => [],
        'total'    => 0,
        'page'     => 1,
        'per_page' => GH_V2_PREVIEW_PER_PAGE,
        'message'  => "La sorgente '{$source_id}' non supporta ne fetch ne select-local.",
    ] );
} );

/**
 * Local catalog preview via WC_Product_Query. Hydrates only the visible
 * page (id, sku, name, price, status, type, thumb URL) — full product
 * objects are never exposed to the client.
 *
 * @return array{items: array, total: int, page: int, per_page: int}
 */
function gh_v2_preview_local( string $search, int $page, int $per_page ): array {
    if ( ! class_exists( 'WC_Product_Query' ) ) {
        return [ 'items' => [], 'total' => 0, 'page' => 1, 'per_page' => $per_page,
                 'message' => 'WooCommerce non attivo.' ];
    }

    $base_args = [
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'ids',
    ];
    if ( $search !== '' ) {
        $base_args['s'] = $search;
    }

    // Total count (ids-only query is cheap).
    $count_args = $base_args + [ 'limit' => -1 ];
    $all_ids = ( new \WC_Product_Query( $count_args ) )->get_products();
    $total   = is_array( $all_ids ) ? count( $all_ids ) : 0;

    $page_args = $base_args + [
        'limit'  => $per_page,
        'offset' => ( $page - 1 ) * $per_page,
    ];
    $page_ids = ( new \WC_Product_Query( $page_args ) )->get_products();
    $items    = [];

    if ( is_array( $page_ids ) ) {
        foreach ( $page_ids as $pid ) {
            $p = wc_get_product( (int) $pid );
            if ( ! $p ) continue;
            $thumb_id = (int) $p->get_image_id();
            $items[]  = [
                'id'     => $p->get_id(),
                'sku'    => (string) $p->get_sku(),
                'name'   => $p->get_name(),
                'price'  => (string) $p->get_price(),
                'status' => $p->get_status(),
                'type'   => $p->get_type(),
                'thumb'  => $thumb_id > 0 ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
            ];
        }
    }

    return [ 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
}

/**
 * Fetch-source preview: cache the FeedItem list in a transient keyed by
 * source_id + config hash, then filter+paginate in-memory.
 *
 * @return array{items: array, total: int, page: int, per_page: int, fetched_at?: int, warnings?: array}
 */
function gh_v2_preview_fetch(
    \GH\Core\Source\Source $source,
    array $config,
    string $search,
    int $page,
    int $per_page,
    bool $force_fresh
): array {
    $cache_key = 'gh_v2_pv_' . md5( $source->id() . '|' . wp_json_encode( $config ) );

    $cached = $force_fresh ? false : get_transient( $cache_key );
    $warnings = [];

    if ( $cached === false ) {
        // Hydrate redacted/empty secret fields from the credentials store
        // so the form's ••••XXXX placeholder never reaches the upstream API.
        if ( function_exists( 'gh_v2_hydrate_credentials' ) ) {
            $config = gh_v2_hydrate_credentials( $source->id(), $config );
        }
        $req = new \GH\Core\Source\FetchRequest( config: $config );
        $ctx = new \GH\Core\Source\Context(
            runId: 'preview_' . wp_generate_uuid4(),
            dryRun: true,
            meta: [ 'origin' => 'workflow_preview' ],
        );
        $result = $source->fetch( $req, $ctx );
        $warnings = $result->warnings ?? [];

        // Flatten FeedItem[] to the cache shape (lean — keep only what
        // the table renderer needs). FeedItem::raw is dropped to keep
        // the transient small.
        $flat = [];
        foreach ( $result->items as $item ) {
            $d = $item->data;
            $flat[] = [
                'sku'    => (string) $item->sku,
                'name'   => (string) ( $d['name'] ?? '' ),
                'price'  => (string) ( $d['regular_price'] ?? $d['price'] ?? '' ),
                'status' => 'remote',
                'type'   => (string) ( $d['type'] ?? 'simple' ),
                'thumb'  => (string) ( $d['_gs_image_url'] ?? $d['image_url'] ?? '' ),
            ];
        }
        $cached = [ 'items' => $flat, 'fetched_at' => time() ];
        if ( ! empty( $flat ) ) {
            set_transient( $cache_key, $cached, GH_V2_PREVIEW_CACHE_TTL );
        }
    }

    $paginated = \GH\Workflow\Preview\InMemoryPaginator::filterAndPaginate(
        $cached['items'] ?? [],
        $search,
        $page,
        $per_page
    );
    $paginated['fetched_at'] = $cached['fetched_at'] ?? null;
    if ( $warnings ) {
        $paginated['warnings'] = $warnings;
    }
    return $paginated;
}
