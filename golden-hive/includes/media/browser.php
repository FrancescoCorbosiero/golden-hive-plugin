<?php
/**
 * Media Browser — motore unificato per la nuova tab "Media Library".
 *
 * Responsabilita:
 * - Costruisce l'indice inverso "attachment_id → [ { pid, role } ]" ed espone
 *   una cache in transient (TTL 10 min) cosi le query paginaged successive non
 *   ri-scansionano l'intero sito. L'indice viene invalidato automaticamente
 *   sugli hook di add/delete attachment e save_post_product.
 * - Query paginaged con filtri (filename, usage=mapped/unmapped, whitelist).
 * - Hydration batch dei prodotti parent per la visualizzazione "Usato da".
 * - Preview del Safe Cleanup con lista whitelist esclusi + statistiche.
 *
 * Nessun hook UI qui — il layer UI vive in views/. Nessun write qui — le
 * operazioni distruttive (delete) passano per media/cleaner.php con tutti i
 * safety check.
 */

defined( 'ABSPATH' ) || exit;

const GH_MEDIA_USAGE_INDEX_KEY = 'gh_media_usage_index_v1';
const GH_MEDIA_USAGE_INDEX_TTL = 600; // 10 minuti

/**
 * Costruisce (o legge dalla cache) l'indice inverso media → usages.
 *
 * @param bool $force_refresh Se true, ignora la cache e ricostruisce.
 * @return array<int, array<int, array{pid:int, role:string}>>
 *         Mappa attachment_id → lista di { pid, role }.
 *         Ruoli possibili: featured, variation, post_featured, gallery, content.
 */
function gh_media_build_usage_index( bool $force_refresh = false ): array {

    if ( ! $force_refresh ) {
        $cached = get_transient( GH_MEDIA_USAGE_INDEX_KEY );
        if ( is_array( $cached ) ) return $cached;
    }

    global $wpdb;
    $index = [];

    // 1. Featured images (un'unica query join postmeta+posts)
    $rows = $wpdb->get_results( "
        SELECT p.ID AS parent_id, p.post_type, pm.meta_value AS thumb_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_thumbnail_id'
          AND pm.meta_value != ''
          AND p.post_type IN ('product','product_variation','post','page')
          AND p.post_status NOT IN ('trash','auto-draft')
    " );

    foreach ( $rows ?: [] as $row ) {
        $tid = (int) $row->thumb_id;
        if ( $tid <= 0 ) continue;
        $role = match ( $row->post_type ) {
            'product'           => 'featured',
            'product_variation' => 'variation',
            'post', 'page'      => 'post_featured',
            default             => 'other',
        };
        $index[ $tid ][] = [ 'pid' => (int) $row->parent_id, 'role' => $role ];
    }

    // 2. Gallery WooCommerce: _product_image_gallery CSV
    $gallery_rows = $wpdb->get_results( "
        SELECT post_id, meta_value
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_product_image_gallery'
          AND meta_value != ''
    " );

    foreach ( $gallery_rows ?: [] as $row ) {
        $pid = (int) $row->post_id;
        foreach ( explode( ',', $row->meta_value ) as $idstr ) {
            $aid = (int) trim( $idstr );
            if ( $aid > 0 ) {
                $index[ $aid ][] = [ 'pid' => $pid, 'role' => 'gallery' ];
            }
        }
    }

    // 3. Inline content: scansione content/excerpt con URL→id map precomputata
    gh_media_scan_content_into_index( $index );

    set_transient( GH_MEDIA_USAGE_INDEX_KEY, $index, GH_MEDIA_USAGE_INDEX_TTL );
    return $index;
}

/**
 * Scansiona il content/excerpt dei post popolando l'indice con ruolo 'content'.
 *
 * Strategia identica a rp_mm_scan_inline_content_attachments() (scanner.php)
 * ma qui manteniamo l'attribuzione per post_id cosi l'indice inverso puo
 * dire "questo media e usato nel content del prodotto #123".
 *
 * @param array &$index Riferimento all'indice in costruzione.
 */
function gh_media_scan_content_into_index( array &$index ): void {

    global $wpdb;

    $content_rows = $wpdb->get_results( "
        SELECT ID, CONCAT_WS(' ', post_content, post_excerpt) AS blob
        FROM {$wpdb->posts}
        WHERE post_type IN ('product','post','page')
          AND post_status NOT IN ('trash','auto-draft')
          AND (post_content LIKE '%src=%' OR post_content LIKE '%href=%'
               OR post_excerpt LIKE '%src=%' OR post_excerpt LIKE '%href=%')
    " );

    if ( empty( $content_rows ) ) return;

    // URL → attachment_id map (una query su guid di tutti gli attachment image)
    $guid_rows = $wpdb->get_results( "
        SELECT ID, guid
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_status = 'inherit'
          AND post_mime_type LIKE 'image/%'
    " );

    $guid_to_id = [];
    foreach ( $guid_rows ?: [] as $r ) {
        $guid_to_id[ $r->guid ] = (int) $r->ID;
    }

    foreach ( $content_rows as $cr ) {
        $pid = (int) $cr->ID;
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']+?\.(?:jpe?g|png|gif|webp|svg))["\']?/i', (string) $cr->blob, $matches ) ) {
            $seen_in_post = [];
            foreach ( $matches[1] as $url ) {
                $aid = $guid_to_id[ $url ] ?? null;
                if ( ! $aid ) {
                    $clean = preg_replace( '/-\d+x\d+(?=\.[a-z]+$)/i', '', $url );
                    if ( $clean !== $url && isset( $guid_to_id[ $clean ] ) ) {
                        $aid = $guid_to_id[ $clean ];
                    }
                }
                if ( $aid && ! isset( $seen_in_post[ $aid ] ) ) {
                    $index[ $aid ][] = [ 'pid' => $pid, 'role' => 'content' ];
                    $seen_in_post[ $aid ] = true;
                }
            }
        }
    }
}

/**
 * Invalida la cache dell'indice usage. Chiamata automaticamente su hook e
 * manualmente dopo operazioni bulk che modificano prodotti/gallerie/media.
 */
function gh_media_invalidate_usage_index(): void {
    delete_transient( GH_MEDIA_USAGE_INDEX_KEY );
}

// Hook: invalida la cache quando cambiano media o prodotti
add_action( 'add_attachment',    'gh_media_invalidate_usage_index' );
add_action( 'delete_attachment', 'gh_media_invalidate_usage_index' );
add_action( 'save_post_product', 'gh_media_invalidate_usage_index' );
add_action( 'woocommerce_update_product', 'gh_media_invalidate_usage_index' );

/**
 * Query paginaged con filtri per la UI Media Library.
 *
 * Filtri supportati:
 * - filename:  substring match su post_title + guid (DB level, LIKE)
 * - usage:     'all' | 'mapped' | 'unmapped'  (memory level, dall'indice)
 * - whitelist: 'all' | 'yes' | 'no'           (memory level)
 *
 * @param array $filters    { filename?, usage?, whitelist? }
 * @param array $pagination { page, per_page, orderby, order }
 * @return array {
 *     items: array        — subset paginato con metadati + usage hydrated
 *     total: int          — totale righe matching i filtri
 *     page: int
 *     per_page: int
 *     total_pages: int
 * }
 */
function gh_media_query( array $filters = [], array $pagination = [] ): array {

    $page     = max( 1, (int) ( $pagination['page'] ?? 1 ) );
    $per_page = max( 10, min( 500, (int) ( $pagination['per_page'] ?? 100 ) ) );
    $orderby  = $pagination['orderby'] ?? 'date';
    $order    = strtoupper( $pagination['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

    // 1. Fetch matching IDs a livello DB (applica filtri filename/mime)
    $all_ids = gh_media_filter_ids_at_db_level( $filters, $orderby, $order );

    // 2. Filtri memory-level (usage / whitelist)
    $usage_filter = $filters['usage']     ?? 'all';
    $wl_filter    = $filters['whitelist'] ?? 'all';

    if ( $usage_filter !== 'all' || $wl_filter !== 'all' ) {
        $usage_index = gh_media_build_usage_index();
        $wl_index    = rp_mm_build_whitelist_index();

        $filtered = [];
        foreach ( $all_ids as $id ) {
            $is_mapped = isset( $usage_index[ $id ] );
            $is_wl     = isset( $wl_index[ $id ] );

            if ( $usage_filter === 'mapped'   && ! $is_mapped ) continue;
            if ( $usage_filter === 'unmapped' &&   $is_mapped ) continue;
            if ( $wl_filter    === 'yes'      && ! $is_wl )     continue;
            if ( $wl_filter    === 'no'       &&   $is_wl )     continue;

            $filtered[] = $id;
        }
        $all_ids = $filtered;
    } else {
        $usage_index = null;
        $wl_index    = null;
    }

    $total       = count( $all_ids );
    $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

    // 3. Paginazione
    $offset   = ( $page - 1 ) * $per_page;
    $page_ids = array_slice( $all_ids, $offset, $per_page );

    // 4. Hydration metadati batched (zero file I/O)
    $items = rp_mm_build_attachment_data_batch( $page_ids );

    // Se non li avevamo gia caricati, prendili ora per l'hydration
    $usage_index = $usage_index ?? gh_media_build_usage_index();
    $wl_index    = $wl_index    ?? rp_mm_build_whitelist_index();

    // Raccogli parent_ids della pagina per hydrate batch
    $parent_ids = [];
    foreach ( $items as $item ) {
        $uses = $usage_index[ $item['id'] ] ?? [];
        foreach ( $uses as $u ) $parent_ids[ $u['pid'] ] = true;
    }
    $parents_info = gh_media_hydrate_parents( array_keys( $parent_ids ) );

    // Componi ogni riga con usage + whitelist + parent info
    foreach ( $items as &$item ) {
        $item['is_whitelisted']   = isset( $wl_index[ $item['id'] ] );
        $item['whitelist_reason'] = $wl_index[ $item['id'] ] ?? null;

        $uses = $usage_index[ $item['id'] ] ?? [];
        $item['usage'] = [];
        foreach ( $uses as $u ) {
            $info = $parents_info[ $u['pid'] ] ?? null;
            $item['usage'][] = [
                'pid'       => $u['pid'],
                'role'      => $u['role'],
                'name'      => $info['name'] ?? "#{$u['pid']}",
                'sku'       => $info['sku'] ?? '',
                'permalink' => $info['permalink'] ?? '',
            ];
        }
    }
    unset( $item );

    return [
        'items'       => array_values( $items ),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ];
}

/**
 * Ritorna SOLO gli ID di tutti i media che matchano i filtri (no pagination,
 * no hydration). Usata per "Seleziona tutti i risultati" e per la preview del
 * Safe Cleanup.
 *
 * @param array $filters
 * @return int[]
 */
function gh_media_query_all_ids( array $filters = [] ): array {

    $all_ids      = gh_media_filter_ids_at_db_level( $filters, 'date', 'DESC' );
    $usage_filter = $filters['usage']     ?? 'all';
    $wl_filter    = $filters['whitelist'] ?? 'all';

    if ( $usage_filter === 'all' && $wl_filter === 'all' ) {
        return $all_ids;
    }

    $usage_index = gh_media_build_usage_index();
    $wl_index    = rp_mm_build_whitelist_index();

    $out = [];
    foreach ( $all_ids as $id ) {
        $is_mapped = isset( $usage_index[ $id ] );
        $is_wl     = isset( $wl_index[ $id ] );

        if ( $usage_filter === 'mapped'   && ! $is_mapped ) continue;
        if ( $usage_filter === 'unmapped' &&   $is_mapped ) continue;
        if ( $wl_filter    === 'yes'      && ! $is_wl )     continue;
        if ( $wl_filter    === 'no'       &&   $is_wl )     continue;

        $out[] = $id;
    }
    return $out;
}

/**
 * Filtra i media a livello DB (mime + filename). Ritorna ID ordinati.
 *
 * @param array  $filters
 * @param string $orderby  'date' | 'id' | 'filename'
 * @param string $order    'ASC' | 'DESC'
 * @return int[]
 */
function gh_media_filter_ids_at_db_level( array $filters, string $orderby, string $order ): array {

    global $wpdb;

    $where  = [ "p.post_type = 'attachment'", "p.post_status = 'inherit'", "p.post_mime_type LIKE 'image/%'" ];
    $params = [];

    $filename = trim( (string) ( $filters['filename'] ?? '' ) );
    if ( $filename !== '' ) {
        $like       = '%' . $wpdb->esc_like( $filename ) . '%';
        $where[]    = '(p.post_title LIKE %s OR p.guid LIKE %s)';
        $params[]   = $like;
        $params[]   = $like;
    }

    $order_col = match ( $orderby ) {
        'id'       => 'p.ID',
        'filename' => 'p.post_title',
        default    => 'p.post_date',
    };

    $sql = "SELECT ID FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where )
         . " ORDER BY {$order_col} {$order}";

    if ( $params ) {
        $sql = $wpdb->prepare( $sql, $params );
    }

    return array_map( 'intval', $wpdb->get_col( $sql ) ?: [] );
}

/**
 * Hydrata informazioni di prodotti/post (name, sku, permalink) in una
 * sola query wpdb + eventuali get_permalink (cached da WP).
 *
 * @param int[] $parent_ids
 * @return array<int, array{name:string, sku:string, permalink:string}>
 */
function gh_media_hydrate_parents( array $parent_ids ): array {

    if ( empty( $parent_ids ) ) return [];

    global $wpdb;
    $parent_ids = array_map( 'intval', $parent_ids );
    $in         = implode( ',', $parent_ids );

    $rows = $wpdb->get_results( "
        SELECT p.ID, p.post_title, p.post_type,
               (SELECT meta_value FROM {$wpdb->postmeta}
                  WHERE post_id = p.ID AND meta_key = '_sku' LIMIT 1) AS sku
        FROM {$wpdb->posts} p
        WHERE p.ID IN ({$in})
    " );

    $out = [];
    foreach ( $rows ?: [] as $r ) {
        $out[ (int) $r->ID ] = [
            'name'      => (string) $r->post_title,
            'sku'       => (string) ( $r->sku ?? '' ),
            'permalink' => (string) ( get_permalink( (int) $r->ID ) ?: '' ),
        ];
    }
    return $out;
}

/**
 * Preview del Safe Cleanup: mostra quanti media verranno eliminati, quanti
 * sono whitelisted (esclusi) e i loro dettagli per ispezione prima di
 * procedere.
 *
 * Il Safe Cleanup e definito come "filter usage=unmapped; escludi whitelist".
 * Qui ritorniamo:
 * - to_delete_ids: ID effettivamente eliminabili (unmapped, non whitelist)
 * - whitelist_details: chi era unmapped MA protetto dalla whitelist
 *
 * @return array {
 *     total_matched: int,
 *     to_delete_count: int,
 *     whitelisted_count: int,
 *     whitelist_details: array<int, array{id:int, url:string, reason:?string}>,
 *     to_delete_ids: int[],
 * }
 */
function gh_media_safe_cleanup_preview(): array {

    // Tutti gli unmapped (indipendenti dalla whitelist)
    $unmapped_ids = gh_media_query_all_ids( [ 'usage' => 'unmapped', 'whitelist' => 'all' ] );

    $wl_index = rp_mm_build_whitelist_index();

    $to_delete   = [];
    $wl_excluded = [];

    foreach ( $unmapped_ids as $id ) {
        if ( isset( $wl_index[ $id ] ) ) {
            $wl_excluded[] = $id;
        } else {
            $to_delete[] = $id;
        }
    }

    // Dettagli per l'UI: URL + reason di ogni whitelist esclusa
    $wl_entries = rp_mm_get_whitelist();
    $wl_by_id   = [];
    foreach ( $wl_entries as $e ) {
        $id = (int) ( $e['id'] ?? 0 );
        if ( $id > 0 ) $wl_by_id[ $id ] = $e;
    }

    $details = [];
    foreach ( $wl_excluded as $id ) {
        $entry = $wl_by_id[ $id ] ?? null;
        $details[] = [
            'id'     => $id,
            'url'    => (string) ( $entry['url'] ?? wp_get_attachment_url( $id ) ?: '' ),
            'reason' => $entry['reason'] ?? null,
        ];
    }

    return [
        'total_matched'     => count( $unmapped_ids ),
        'to_delete_count'   => count( $to_delete ),
        'whitelisted_count' => count( $wl_excluded ),
        'whitelist_details' => $details,
        'to_delete_ids'     => $to_delete,
    ];
}

/**
 * Preview del "Rimuovi da gallerie": mostra quali prodotti verranno toccati
 * per la selezione di media passata.
 *
 * @param int[] $media_ids
 * @return array {
 *     products: array<int, { id, name, sku, removals }>,
 *     total_removals: int,
 *     affected_count: int,
 * }
 */
function gh_media_gallery_removal_preview( array $media_ids ): array {

    $usage_index = gh_media_build_usage_index();

    $affected       = []; // pid => removals count
    $total_removals = 0;

    foreach ( $media_ids as $mid ) {
        $mid  = (int) $mid;
        $uses = $usage_index[ $mid ] ?? [];
        foreach ( $uses as $u ) {
            if ( $u['role'] === 'gallery' ) {
                $affected[ $u['pid'] ] = ( $affected[ $u['pid'] ] ?? 0 ) + 1;
                $total_removals++;
            }
        }
    }

    $parents = gh_media_hydrate_parents( array_keys( $affected ) );

    $products = [];
    foreach ( $affected as $pid => $count ) {
        $info = $parents[ $pid ] ?? [ 'name' => "#{$pid}", 'sku' => '', 'permalink' => '' ];
        $products[] = [
            'id'        => $pid,
            'name'      => $info['name'],
            'sku'       => $info['sku'],
            'permalink' => $info['permalink'],
            'removals'  => $count,
        ];
    }

    return [
        'products'       => $products,
        'total_removals' => $total_removals,
        'affected_count' => count( $products ),
    ];
}

/**
 * Esegue la rimozione dei media dalle gallery dei prodotti che li contengono.
 *
 * Ogni prodotto e caricato una sola volta e la sua gallery viene riscritta
 * senza gli ID richiesti. NON elimina i media dalla library (questo e solo
 * "unlink" dalla gallery).
 *
 * @param int[] $media_ids
 * @return array { affected_products: int, removals: int }
 */
function gh_media_remove_from_galleries( array $media_ids ): array {

    $usage_index = gh_media_build_usage_index();

    // Raggruppa: pid → set di media_ids da strippare
    $strip_map_by_pid = [];
    foreach ( $media_ids as $mid ) {
        $mid  = (int) $mid;
        $uses = $usage_index[ $mid ] ?? [];
        foreach ( $uses as $u ) {
            if ( $u['role'] === 'gallery' ) {
                $strip_map_by_pid[ $u['pid'] ][ $mid ] = true;
            }
        }
    }

    $affected = 0;
    $removals = 0;

    foreach ( $strip_map_by_pid as $pid => $strip_set ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;
        $current = $product->get_gallery_image_ids();
        $new     = array_values( array_filter( $current, fn( $g ) => ! isset( $strip_set[ (int) $g ] ) ) );
        if ( count( $new ) !== count( $current ) ) {
            $product->set_gallery_image_ids( $new );
            $product->save();
            $affected++;
            $removals += count( $current ) - count( $new );
        }
    }

    gh_media_invalidate_usage_index();

    return [
        'affected_products' => $affected,
        'removals'          => $removals,
    ];
}
