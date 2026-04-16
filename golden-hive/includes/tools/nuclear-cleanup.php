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
 * @param array $targets { products, media, transients, taxonomy, orphan_meta }
 * @return array Per-category results.
 */
function gh_nuclear_execute( array $targets ): array {
    global $wpdb;

    $results = [];

    // 1. Products (must come before media so product images are detached first)
    if ( ! empty( $targets['products'] ) ) {
        $product_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
        );
        $deleted = 0;
        foreach ( $product_ids as $pid ) {
            wp_delete_post( (int) $pid, true );
            $deleted++;
        }
        // Clear WC product lookup table
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_product_meta_lookup" );
        $results['products'] = $deleted;
    }

    // 2. Media (respect whitelist)
    if ( ! empty( $targets['media'] ) ) {
        $wl_ids     = gh_nuclear_get_whitelisted_ids();
        $all_images = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
        $deleted  = 0;
        $skipped  = 0;
        foreach ( $all_images as $att_id ) {
            $att_id = (int) $att_id;
            if ( in_array( $att_id, $wl_ids, true ) ) {
                $skipped++;
                continue;
            }
            wp_delete_attachment( $att_id, true );
            $deleted++;
        }
        $results['media'] = [ 'deleted' => $deleted, 'protected' => $skipped ];
    }

    // 3. Transients & cache
    if ( ! empty( $targets['transients'] ) ) {
        $del_wp = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
        );
        $del_wc = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wc_%_transient_%' OR option_name LIKE '_wc_%'"
        );
        wc_delete_product_transients();
        wp_cache_flush();
        $results['transients'] = $del_wp + $del_wc;
    }

    // 4. Taxonomy
    if ( ! empty( $targets['taxonomy'] ) ) {
        $tax_deleted = 0;

        foreach ( [ 'product_cat', 'product_brand', 'product_tag' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) continue;
            $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ] );
            if ( is_wp_error( $terms ) ) continue;
            foreach ( $terms as $term_id ) {
                wp_delete_term( (int) $term_id, $taxonomy );
                $tax_deleted++;
            }
        }

        $results['taxonomy'] = $tax_deleted;
    }

    // 5. Orphan meta & WC sessions
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
