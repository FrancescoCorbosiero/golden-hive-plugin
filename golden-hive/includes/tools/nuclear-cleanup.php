<?php
/**
 * Nuclear Cleanup — selective bulk deletion of products, media, taxonomy,
 * transients, and other WooCommerce/WordPress data.
 *
 * Respects the media whitelist: whitelisted attachments are never deleted.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts what would be deleted for each category (dry-run).
 *
 * @param array $targets { products, media, transients, taxonomy, orphan_meta }
 * @return array Per-category counts + details.
 */
function gh_nuclear_preview( array $targets ): array {
    global $wpdb;

    $preview = [];

    if ( ! empty( $targets['products'] ) ) {
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
        );
        $preview['products'] = [ 'count' => $count, 'label' => 'Prodotti + varianti' ];
    }

    if ( ! empty( $targets['media'] ) ) {
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
        $wl_ids    = gh_nuclear_get_whitelisted_ids();
        $protected = count( $wl_ids );
        $to_delete = max( 0, $total - $protected );
        $preview['media'] = [
            'count'     => $to_delete,
            'protected' => $protected,
            'total'     => $total,
            'label'     => "Immagini ({$to_delete} da eliminare, {$protected} protette)",
        ];
    }

    if ( ! empty( $targets['transients'] ) ) {
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
        );
        $wc_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wc_%_transient_%' OR option_name LIKE '_wc_%'"
        );
        $preview['transients'] = [
            'count' => $count + $wc_count,
            'label' => "Transients ({$count} WP + {$wc_count} WC)",
        ];
    }

    if ( ! empty( $targets['taxonomy'] ) ) {
        $cats   = (int) wp_count_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
        $brands = taxonomy_exists( 'product_brand' )
            ? (int) wp_count_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] )
            : 0;
        $tags   = (int) wp_count_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false ] );
        $attrs  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_attribute_taxonomies"
        );
        $preview['taxonomy'] = [
            'count'  => $cats + $brands + $tags,
            'cats'   => $cats,
            'brands' => $brands,
            'tags'   => $tags,
            'attrs'  => $attrs,
            'label'  => "Tassonomie ({$cats} cat, {$brands} brand, {$tags} tag, {$attrs} attr)",
        ];
    }

    if ( ! empty( $targets['orphan_meta'] ) ) {
        $orphan_postmeta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
        $wc_sessions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%'"
        );
        $preview['orphan_meta'] = [
            'count' => $orphan_postmeta + $wc_sessions,
            'orphan_postmeta' => $orphan_postmeta,
            'wc_sessions'     => $wc_sessions,
            'label' => "Orfani ({$orphan_postmeta} postmeta, {$wc_sessions} sessioni WC)",
        ];
    }

    return $preview;
}

/**
 * Executes the nuclear cleanup for selected targets.
 *
 * Uses direct SQL for speed. For 2000 products + 17k media, this takes
 * seconds not hours. Media files are deleted in batches of 500 via
 * wp_delete_attachment (needed to remove files from disk), but with
 * hooks suppressed for speed.
 *
 * @param array $targets { products, media, transients, taxonomy, orphan_meta }
 * @return array Per-category results.
 */
function gh_nuclear_execute( array $targets ): array {
    global $wpdb;

    $results = [];

    // 1. Products — pure SQL nuke (no wp_delete_post loop)
    if ( ! empty( $targets['products'] ) ) {
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
        );

        // Delete term relationships for products
        $wpdb->query(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
             WHERE p.post_type IN ('product', 'product_variation')"
        );

        // Delete postmeta for products
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type IN ('product', 'product_variation')"
        );

        // Delete comments (reviews) on products
        $wpdb->query(
            "DELETE c FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
             WHERE p.post_type = 'product'"
        );

        // Delete the posts themselves
        $wpdb->query(
            "DELETE FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
        );

        // Clear WC lookup tables
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_product_meta_lookup" );

        $results['products'] = $count;
    }

    // 2. Media — batch wp_delete_attachment (needed to remove files from disk)
    if ( ! empty( $targets['media'] ) ) {
        $wl_ids = gh_nuclear_get_whitelisted_ids();

        $where_not_wl = '';
        if ( ! empty( $wl_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $wl_ids ), '%d' ) );
            $where_not_wl = $wpdb->prepare( " AND ID NOT IN ($placeholders)", ...$wl_ids );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" . $where_not_wl
        );

        // Suppress hooks for speed
        remove_all_actions( 'delete_attachment' );
        remove_all_actions( 'wp_delete_file' );

        $deleted = 0;
        $batch   = 500;

        while ( true ) {
            $ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
                . $where_not_wl . " LIMIT {$batch}"
            );
            if ( empty( $ids ) ) break;

            foreach ( $ids as $att_id ) {
                wp_delete_attachment( (int) $att_id, true );
                $deleted++;
            }
        }

        $results['media'] = [ 'deleted' => $deleted, 'protected' => count( $wl_ids ) ];
    }

    // 3. Transients & cache — pure SQL
    if ( ! empty( $targets['transients'] ) ) {
        $del_wp = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
        );
        $del_wc = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wc_%_transient_%' OR option_name LIKE '_wc_%'"
        );
        wp_cache_flush();
        $results['transients'] = $del_wp + $del_wc;
    }

    // 4. Taxonomy — bulk SQL delete per taxonomy
    if ( ! empty( $targets['taxonomy'] ) ) {
        $tax_deleted = 0;

        foreach ( [ 'product_cat', 'product_brand', 'product_tag' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) continue;

            $term_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = %s",
                $taxonomy
            ) );
            if ( empty( $term_ids ) ) continue;

            $id_list = implode( ',', array_map( 'intval', $term_ids ) );

            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (
                SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = '{$taxonomy}'
            )" );
            $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = '{$taxonomy}'" );
            $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$id_list})" );
            $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$id_list})" );

            $tax_deleted += count( $term_ids );
        }

        $results['taxonomy'] = $tax_deleted;
    }

    // 5. Orphan meta & WC sessions — pure SQL
    if ( ! empty( $targets['orphan_meta'] ) ) {
        $del_meta = (int) $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
        $del_sessions = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%'"
        );
        $results['orphan_meta'] = [ 'postmeta' => $del_meta, 'sessions' => $del_sessions ];
    }

    // Final: flush all caches
    wp_cache_flush();

    return $results;
}

/**
 * Gets attachment IDs that are whitelisted (protected from deletion).
 *
 * @return int[]
 */
function gh_nuclear_get_whitelisted_ids(): array {
    $whitelist = rp_mm_get_whitelist();
    $ids = [];
    foreach ( $whitelist as $entry ) {
        if ( ! empty( $entry['id'] ) ) {
            $ids[] = (int) $entry['id'];
        }
    }
    return $ids;
}

/**
 * Counts products imported from a specific feed source.
 * Checks _gh_import_source, _feed_source, and the feed's tag as fallbacks
 * for products imported before provenance tagging was added.
 *
 * @param string $source Feed source key: 'stockfirmati', 'goldensneakers', 'config', 'csv'
 * @return int
 */
function gh_feed_count_products( string $source ): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'product'
         AND (
             (pm.meta_key = '_gh_import_source' AND pm.meta_value = %s)
             OR (pm.meta_key = '_feed_source' AND pm.meta_value = %s)
         )",
        $source, $source
    ) );
}

/**
 * Deletes all products (and their variations) imported from a specific feed source.
 * Uses direct SQL for speed — no wp_delete_post loop.
 * Checks _gh_import_source, _feed_source, and feed tag as fallbacks.
 *
 * @param string $source Feed source key.
 * @return array { deleted: int, variations: int }
 */
function gh_feed_cleanup_products( string $source ): array {
    global $wpdb;

    // Find parent product IDs tagged with this source via meta OR tag
    $tag_slug = match( $source ) {
        'stockfirmati'  => 'stockfirmati',
        'goldensneakers' => 'super-sale',
        default          => '',
    };

    $parent_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'product'
         AND (
             (pm.meta_key = '_gh_import_source' AND pm.meta_value = %s)
             OR (pm.meta_key = '_feed_source' AND pm.meta_value = %s)
         )",
        $source, $source
    ) );

    // Fallback: also find by product_tag if meta didn't catch them all
    if ( $tag_slug ) {
        $tag_term = get_term_by( 'slug', $tag_slug, 'product_tag' );
        if ( $tag_term ) {
            $tag_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 WHERE p.post_type = 'product'
                 AND tr.term_taxonomy_id = %d",
                $tag_term->term_taxonomy_id
            ) );
            $parent_ids = array_values( array_unique( array_merge( $parent_ids, $tag_ids ) ) );
        }
    }

    if ( empty( $parent_ids ) ) {
        return [ 'deleted' => 0, 'variations' => 0 ];
    }

    $ids_csv = implode( ',', array_map( 'intval', $parent_ids ) );

    // 2. Find all variation IDs belonging to these parents
    $var_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'product_variation' AND post_parent IN ({$ids_csv})"
    );
    $var_count  = count( $var_ids );
    $all_ids    = array_merge( $parent_ids, $var_ids );
    $all_csv    = implode( ',', array_map( 'intval', $all_ids ) );

    // 3. Bulk SQL delete — same pattern as nuclear cleanup but scoped
    $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$all_csv})" );
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$all_csv})" );
    $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ids_csv})" );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$all_csv})" );

    // 4. Clean up WC lookup table for these products
    $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ({$all_csv})" );

    // 5. Flush caches
    wc_delete_product_transients();
    wp_cache_flush();

    return [
        'deleted'    => count( $parent_ids ),
        'variations' => $var_count,
    ];
}
