<?php
/**
 * Scanner — identifica attachment orfani nella media library.
 * Nessun side effect, solo lettura.
 *
 * Tutte le funzioni sono ottimizzate per store di medie/grandi dimensioni:
 * - Usano query $wpdb dirette invece di caricare oggetti WP_Post completi
 * - Fanno file I/O (filesize) SOLO sugli orfani, non su tutta la media library
 * - Costruiscono una mappa URL→attachment_id in UNA query invece di chiamare
 *   attachment_url_to_postid() per ogni URL trovato nel content
 *
 * Il Safe Cleanup puo cosi scansionare migliaia di prodotti senza esaurire
 * memoria o andare in timeout.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ritorna SOLO gli ID di tutti gli attachment (fast path).
 *
 * Usato dal Safe Cleanup per il calcolo del diff: non serve caricare
 * filesize/URL/thumb per ogni attachment, solo per gli orfani finali.
 *
 * @param string $mime_filter 'all' | 'image' | 'video' | 'application'
 * @return int[]
 */
function rp_mm_get_all_attachment_ids( string $mime_filter = 'all' ): array {

    global $wpdb;

    $where  = "post_type = 'attachment' AND post_status = 'inherit'";
    $params = [];

    if ( $mime_filter !== 'all' ) {
        $where   .= ' AND post_mime_type LIKE %s';
        $params[] = $mime_filter . '/%';
    }

    $sql = "SELECT ID FROM {$wpdb->posts} WHERE {$where}";
    if ( $params ) {
        $sql = $wpdb->prepare( $sql, $params );
    }

    $ids = $wpdb->get_col( $sql );
    return array_map( 'intval', $ids ?: [] );
}

/**
 * Ritorna tutti gli attachment con metadati completi.
 *
 * ATTENZIONE: questa funzione fa file I/O (filesize) su ogni attachment.
 * Preferire rp_mm_get_all_attachment_ids() se servono solo gli ID.
 *
 * @param string $mime_filter 'all' | 'image' | 'video' | 'application'
 * @return array [ [ id, url, filename, filesize, filesize_human, date, mime_type, thumbnail_url ] ]
 */
function rp_mm_get_all_attachments( string $mime_filter = 'all' ): array {

    $ids    = rp_mm_get_all_attachment_ids( $mime_filter );
    $result = [];

    foreach ( $ids as $id ) {
        $result[] = rp_mm_build_attachment_data( $id );
    }

    return $result;
}

/**
 * Ritorna gli ID di tutti gli attachment attualmente in uso.
 *
 * Wrapper di comodo che appiattisce l'output di rp_mm_build_usage_map().
 * Mantenuto per compatibilita con i chiamanti esistenti (cleaner, scanner).
 *
 * @return int[] Array deduplicato di attachment ID.
 */
function rp_mm_get_used_attachment_ids(): array {

    $map = rp_mm_build_usage_map();
    return $map['all_used'];
}

/**
 * Costruisce la mappa "media in uso" con breakdown per sorgente.
 *
 * Primitiva del Safe Cleanup. Ogni sorgente ritorna la sua lista di ID, cosi
 * l'UI puo mostrare un breakdown ispezionabile. Il set `all_used` e l'unione
 * deduplicata: il diff con tutti gli attachment restituisce gli orfani.
 *
 * Ottimizzazioni critiche:
 * - Usa query $wpdb dirette per _thumbnail_id e _product_image_gallery
 *   invece di get_posts() che carica WP_Post completi.
 * - Per il content scanning costruisce una URL→attachment_id map in UNA
 *   singola query invece di chiamare attachment_url_to_postid() per ogni
 *   URL (che farebbe 1 query ciascuna).
 *
 * @return array {
 *     featured_products:  int[] — featured image dei prodotti simple/variable
 *     featured_variations:int[] — thumbnail delle varianti
 *     gallery_products:   int[] — ID in _product_image_gallery (dedup)
 *     featured_posts:     int[] — featured di post/page
 *     inline_content:     int[] — riferiti via <img src> o <a href> nel content
 *     all_used:           int[] — unione deduplicata di tutti i precedenti
 * }
 */
function rp_mm_build_usage_map(): array {

    global $wpdb;

    // 1. Featured images: un'unica query per tutti i _thumbnail_id
    //    indipendentemente dal post_type. Poi dividiamo per tipo.
    $sql = "
        SELECT p.ID AS parent_id, p.post_type, pm.meta_value AS thumb_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_thumbnail_id'
          AND pm.meta_value != ''
          AND p.post_type IN ('product','product_variation','post','page')
    ";
    $rows = $wpdb->get_results( $sql );

    $featured_products   = [];
    $featured_variations = [];
    $featured_posts      = [];

    foreach ( $rows ?: [] as $row ) {
        $tid = (int) $row->thumb_id;
        if ( $tid <= 0 ) continue;
        switch ( $row->post_type ) {
            case 'product':
                $featured_products[] = $tid;
                break;
            case 'product_variation':
                $featured_variations[] = $tid;
                break;
            case 'post':
            case 'page':
                $featured_posts[] = $tid;
                break;
        }
    }

    // 2. Gallery WooCommerce: CSV in _product_image_gallery.
    $gallery_csvs = $wpdb->get_col( "
        SELECT meta_value
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_product_image_gallery'
          AND meta_value != ''
    " );

    $gallery_products = [];
    foreach ( $gallery_csvs ?: [] as $csv ) {
        foreach ( explode( ',', $csv ) as $id ) {
            $id = (int) trim( $id );
            if ( $id > 0 ) $gallery_products[] = $id;
        }
    }

    // 3. Immagini inline nel content/excerpt.
    //    Invece di caricare tutti i WP_Post, selezioniamo SOLO le colonne
    //    che ci servono (post_content + post_excerpt) con un wpdb->get_col.
    //    Poi costruiamo una URL→attachment_id map in UNA query dal $wpdb->posts
    //    (attachment guid), invece di chiamare attachment_url_to_postid() per
    //    ogni URL (che farebbe una query ciascuna).
    $inline_content = rp_mm_scan_inline_content_attachments();

    // Dedup locale (ogni sorgente) + unione globale
    $featured_products   = array_values( array_unique( $featured_products ) );
    $featured_variations = array_values( array_unique( $featured_variations ) );
    $gallery_products    = array_values( array_unique( $gallery_products ) );
    $featured_posts      = array_values( array_unique( $featured_posts ) );
    $inline_content      = array_values( array_unique( $inline_content ) );

    $all_used = array_values( array_unique( array_merge(
        $featured_products,
        $featured_variations,
        $gallery_products,
        $featured_posts,
        $inline_content
    ) ) );

    return [
        'featured_products'   => $featured_products,
        'featured_variations' => $featured_variations,
        'gallery_products'    => $gallery_products,
        'featured_posts'      => $featured_posts,
        'inline_content'      => $inline_content,
        'all_used'            => $all_used,
    ];
}

/**
 * Scansiona il content/excerpt dei post e ritorna gli attachment referenziati.
 *
 * Strategia:
 * 1. UNA query wpdb per fetchare solo post_content + post_excerpt dei post
 *    che potenzialmente contengono media (product, post, page).
 * 2. UNA query wpdb per costruire una mappa URL→attachment_id dal
 *    $wpdb->posts.guid di tutti gli attachment immagine.
 * 3. Regex su ogni content + lookup O(1) nella mappa.
 *
 * Il guid non e garantito essere l'URL pubblico finale, ma in pratica per
 * le installazioni standard di WP contiene l'URL originale dell'upload ed
 * e quello che serve per identificare match inline. Non e infallibile (URLs
 * riscritti, CDN, crop size diverse) ma e quello che faceva anche la vecchia
 * implementazione con attachment_url_to_postid, solo molto piu veloce.
 *
 * @return int[] Attachment ID trovati inline.
 */
function rp_mm_scan_inline_content_attachments(): array {

    global $wpdb;

    // Solo colonne content/excerpt (niente oggetti WP_Post completi)
    $contents = $wpdb->get_col( "
        SELECT CONCAT_WS(' ', post_content, post_excerpt)
        FROM {$wpdb->posts}
        WHERE post_type IN ('product','post','page')
          AND post_status NOT IN ('trash','auto-draft')
          AND (post_content LIKE '%src=%' OR post_content LIKE '%href=%'
               OR post_excerpt LIKE '%src=%' OR post_excerpt LIKE '%href=%')
    " );

    if ( empty( $contents ) ) return [];

    // Raccogli tutti gli URL distinti in un'unica passata
    $urls = [];
    foreach ( $contents as $blob ) {
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']+?\.(?:jpe?g|png|gif|webp|svg))["\']?/i', $blob, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $urls[ $url ] = true;
            }
        }
    }

    if ( empty( $urls ) ) return [];

    // Mappa URL→ID (guid) in UNA query: pre-fetch di tutti gli attachment
    // guid. Per evitare di caricare decine di migliaia di righe in store
    // enormi, puoi limitare per mime type (solo image).
    $rows = $wpdb->get_results( "
        SELECT ID, guid
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_status = 'inherit'
          AND post_mime_type LIKE 'image/%'
    " );

    $guid_to_id = [];
    foreach ( $rows ?: [] as $r ) {
        $guid_to_id[ $r->guid ] = (int) $r->ID;
    }

    // Match: prima exact, poi suffix (utile quando nel content c'e un URL
    // con "-300x200" appeso dalla scalatura WP).
    $found = [];
    foreach ( array_keys( $urls ) as $url ) {
        if ( isset( $guid_to_id[ $url ] ) ) {
            $found[] = $guid_to_id[ $url ];
            continue;
        }
        // Fallback: basename match (senza le size suffixes WP)
        $clean = preg_replace( '/-\d+x\d+(?=\.[a-z]+$)/i', '', $url );
        if ( $clean !== $url && isset( $guid_to_id[ $clean ] ) ) {
            $found[] = $guid_to_id[ $clean ];
        }
    }

    return $found;
}

/**
 * Ritorna gli attachment orfani (non in uso e non in whitelist).
 *
 * Ottimizzato per store grandi (17k+ media):
 * - Diff su ID set (no oggetti WP_Post)
 * - Metadati orfani fetchati in 2 query $wpdb batched (no file I/O, no
 *   wp_get_attachment_url/get_attached_file/filesize per-chiamata)
 * - Whitelist index caricato una volta invece di scansionare l'option
 *   per ogni orfano
 * - Cap opzionale sul numero di orfani "full data" restituiti all'UI:
 *   gli ID completi sono sempre restituiti separatamente cosi il bulk
 *   delete funziona anche sopra il cap
 *
 * @param array|null $usage_map  Map precomputata da rp_mm_build_usage_map().
 * @param int        $data_limit Max orfani con metadati completi. 0 = tutti.
 *                               Gli ID oltre il cap sono sempre disponibili
 *                               in 'orphan_ids' del risultato.
 * @return array {
 *     orphans:    array  — metadati completi (capped a $data_limit)
 *     orphan_ids: int[]  — TUTTI gli ID orfani (non capped)
 * }
 */
function rp_mm_get_orphan_attachments( ?array $usage_map = null, int $data_limit = 0 ): array {

    // 1. Tutti gli ID immagine (fast: solo SELECT ID)
    $all_image_ids = rp_mm_get_all_attachment_ids( 'image' );

    // 2. Set degli usati
    $usage_map = $usage_map ?? rp_mm_build_usage_map();
    $used_flip = array_flip( $usage_map['all_used'] );

    // 3. Diff: ID orfani
    $orphan_ids = [];
    foreach ( $all_image_ids as $id ) {
        if ( ! isset( $used_flip[ $id ] ) ) $orphan_ids[] = $id;
    }

    // 4. Determina quale subset caricare come "full data"
    $data_ids = $data_limit > 0
        ? array_slice( $orphan_ids, 0, $data_limit )
        : $orphan_ids;

    // 5. Batch build: 2 query per tutti gli ID del subset
    $orphans = rp_mm_build_attachment_data_batch( $data_ids );

    // 6. Whitelist index caricato UNA volta
    $wl_index = rp_mm_build_whitelist_index();
    foreach ( $orphans as &$att ) {
        $att['is_whitelisted']   = isset( $wl_index[ $att['id'] ] );
        $att['whitelist_reason'] = $wl_index[ $att['id'] ] ?? null;
    }
    unset( $att );

    return [
        'orphans'    => $orphans,
        'orphan_ids' => $orphan_ids,
    ];
}

/**
 * Costruisce la mappa attachment_id → reason della whitelist UNA sola volta.
 * Evita O(n × m) di scansioni ripetute quando stiamo flaggando migliaia di
 * orfani.
 *
 * @return array<int,?string> [ attachment_id => reason ]
 */
function rp_mm_build_whitelist_index(): array {

    $list  = rp_mm_get_whitelist();
    $index = [];

    foreach ( $list as $entry ) {
        $id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
        if ( $id > 0 ) {
            $index[ $id ] = $entry['reason'] ?? null;
        }
    }
    return $index;
}

/**
 * Costruisce i metadati di N attachment in 2 query $wpdb batched.
 *
 * Versione ottimizzata di rp_mm_build_attachment_data() per operazioni di
 * massa. Niente disk I/O: la filesize viene letta dai metadati serializzati
 * (_wp_attachment_metadata['filesize'], disponibile da WP 6.0+). La
 * thumbnail URL viene costruita dal $upload_dir['baseurl'] + la sub-sizes
 * salvata nei metadati.
 *
 * Per store enormi le IDs vengono processate in chunk da 2000 per evitare
 * problemi con max_allowed_packet sulla clausola IN.
 *
 * @param int[] $ids
 * @return array
 */
function rp_mm_build_attachment_data_batch( array $ids ): array {

    if ( empty( $ids ) ) return [];

    global $wpdb;

    $upload_dir = wp_get_upload_dir();
    $baseurl    = $upload_dir['baseurl'] ?? '';

    $result = [];

    // Chunk per evitare clausole IN gigantesche
    foreach ( array_chunk( $ids, 2000 ) as $chunk ) {
        $chunk = array_map( 'intval', $chunk );
        $in    = implode( ',', $chunk );

        // Query 1: base post data
        $posts = $wpdb->get_results(
            "SELECT ID, post_date, post_mime_type, guid
             FROM {$wpdb->posts}
             WHERE ID IN ({$in})",
            OBJECT_K
        );

        // Query 2: i due meta_key che ci servono
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$in})
               AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata')"
        );

        $meta_by_post = [];
        foreach ( $meta_rows ?: [] as $r ) {
            $meta_by_post[ (int) $r->post_id ][ $r->meta_key ] = $r->meta_value;
        }

        foreach ( $chunk as $id ) {
            $post = $posts[ $id ] ?? null;
            if ( ! $post ) continue;

            $file     = $meta_by_post[ $id ]['_wp_attached_file'] ?? '';
            $raw_meta = $meta_by_post[ $id ]['_wp_attachment_metadata'] ?? '';
            $meta_arr = $raw_meta ? @unserialize( $raw_meta ) : [];
            if ( ! is_array( $meta_arr ) ) $meta_arr = [];

            // Filesize: preferisci quella serializzata (no disk I/O)
            $filesize = isset( $meta_arr['filesize'] ) ? (int) $meta_arr['filesize'] : 0;

            // URL: baseurl + file, fallback al guid
            $url = $file ? trailingslashit( $baseurl ) . ltrim( $file, '/' ) : (string) $post->guid;

            // Thumbnail URL: usa la sub-size "thumbnail" se presente nei metadati
            $thumb_url = $url;
            if ( ! empty( $meta_arr['sizes']['thumbnail']['file'] ) && $file ) {
                $dir = dirname( $file );
                $thumb_url = trailingslashit( $baseurl )
                    . ( $dir !== '.' && $dir !== '' ? trailingslashit( $dir ) : '' )
                    . $meta_arr['sizes']['thumbnail']['file'];
            }

            $result[] = [
                'id'             => (int) $id,
                'url'            => $url,
                'filename'       => $file ? basename( $file ) : '',
                'filesize'       => $filesize,
                'filesize_human' => $filesize ? size_format( $filesize ) : '—',
                'date'           => $post->post_date,
                'mime_type'      => $post->post_mime_type,
                'thumbnail_url'  => $thumb_url,
            ];
        }
    }

    return $result;
}

/**
 * Stima la dimensione degli orfani a partire dal subset con metadati.
 *
 * NOTA: con store enormi il subset puo essere cappato (data_limit), quindi
 * la stima e "almeno X bytes". L'UI dovrebbe dirlo esplicitamente.
 *
 * @param array $orphans_with_meta Orfani con metadati (array 'orphans' da
 *                                  rp_mm_get_orphan_attachments).
 * @return array [ count, total_bytes, total_human, capped ]
 */
function rp_mm_estimate_orphan_size( array $orphans_with_meta ): array {

    $total_bytes = 0;
    $deletable   = 0;

    foreach ( $orphans_with_meta as $att ) {
        if ( ! empty( $att['is_whitelisted'] ) ) continue;
        $total_bytes += $att['filesize'] ?? 0;
        $deletable++;
    }

    return [
        'count'       => $deletable,
        'total_bytes' => $total_bytes,
        'total_human' => size_format( $total_bytes ),
    ];
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Costruisce l'oggetto dati per un singolo attachment.
 *
 * Fa 1 disk stat (filesize) + 1-2 query metadata. Costoso: chiamare solo
 * quando serve visualizzare l'attachment nell'UI.
 *
 * @param int $id Attachment ID.
 * @return array
 */
function rp_mm_build_attachment_data( int $id ): array {

    $url      = wp_get_attachment_url( $id );
    $file     = get_attached_file( $id );
    $filesize = $file && file_exists( $file ) ? filesize( $file ) : 0;
    $post     = get_post( $id );
    $thumb    = wp_get_attachment_image_src( $id, 'thumbnail' );

    return [
        'id'             => $id,
        'url'            => $url ?: '',
        'filename'       => $file ? basename( $file ) : '',
        'filesize'       => $filesize,
        'filesize_human' => size_format( $filesize ),
        'date'           => $post?->post_date ?? '',
        'mime_type'      => $post?->post_mime_type ?? '',
        'thumbnail_url'  => $thumb[0] ?? $url,
    ];
}
