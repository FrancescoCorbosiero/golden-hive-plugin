<?php
declare(strict_types=1);

namespace HiveSync\Tools;

/**
 * Variation Attribute Repair — fixes variations whose taxonomy-attribute
 * meta values are stored as the raw FEED value instead of the WP term
 * SLUG. Caused by the pre-fix GS bridge update path (a6bd7b9) which
 * inlined a stripped-down variation-create that skipped term slug
 * resolution. The variation row exists in DB but Woo can't link it to
 * the parent's taxonomy term — invisible on the storefront dropdown.
 *
 * Symptoms:
 *   - Product has 6 sizes in the feed (e.g. 33, 33.5, 34, 27.5, 28, 30)
 *   - Storefront dropdown shows only 4 (the integer-valued ones)
 *   - The missing two have variation rows in wp_posts with
 *     attribute_pa_taglia="33.5" instead of "33-5" (the term slug)
 *
 * Repair logic per variation meta row:
 *   1. Read attribute_pa_xxx meta value (e.g. "33.5")
 *   2. Look up term in pa_xxx taxonomy by NAME (handles raw feed value)
 *   3. If term found AND its slug !== stored meta value → rewrite to slug
 *   4. If meta value already === term->slug → no-op (idempotent on re-run)
 *
 * Scope: ONLY mutates attribute_pa_* meta keys on product_variation
 * posts. Doesn't touch prices, stock, status, or non-taxonomy attribute
 * meta (e.g. local attributes like attribute_size that aren't backed
 * by a pa_* taxonomy).
 */
final class VariationAttrRepair
{
    /**
     * Scan all variation attribute meta rows and count those that need
     * repair. Returns the count + a small sample of the first N broken
     * variations so the operator can sanity-check before applying.
     *
     * @return array{
     *   scanned: int,
     *   broken: int,
     *   already_ok: int,
     *   missing_term: int,
     *   samples: array<int, array{vid:int, parent_id:int, sku:string, taxonomy:string, current:string, correct:string}>
     * }
     */
    public static function preview(): array
    {
        $rows = self::loadAttributeMetaRows();

        $broken      = 0;
        $alreadyOk   = 0;
        $missingTerm = 0;
        $samples     = [];

        foreach ($rows as $row) {
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            $current  = (string) ($row['meta_value'] ?? '');
            if ($taxonomy === '' || $current === '') {
                continue;
            }
            $correctSlug = self::resolveCorrectSlug($taxonomy, $current);
            if ($correctSlug === null) {
                $missingTerm++;
                continue;
            }
            if ($correctSlug === $current) {
                $alreadyOk++;
                continue;
            }
            $broken++;
            if (count($samples) < 25) {
                $samples[] = [
                    'vid'       => (int) $row['post_id'],
                    'parent_id' => (int) $row['parent_id'],
                    'sku'       => self::variationSku((int) $row['post_id']),
                    'taxonomy'  => $taxonomy,
                    'current'   => $current,
                    'correct'   => $correctSlug,
                ];
            }
        }

        return [
            'scanned'      => count($rows),
            'broken'       => $broken,
            'already_ok'   => $alreadyOk,
            'missing_term' => $missingTerm,
            'samples'      => $samples,
        ];
    }

    /**
     * Apply repairs. Walks the same scan, updates broken meta rows in
     * place. Each row update triggers transient invalidation for the
     * parent product so Woo's variation cache picks up the new value.
     *
     * Designed to fit inside a single AJAX call — the workload is
     * O(broken_count) UPDATE statements (no joins). Even a catalogue
     * with 10k broken variations completes in a few seconds since each
     * UPDATE is keyed by (post_id, meta_key) which is indexed.
     *
     * @return array{repaired: int, missing_term: int, parents_touched: int}
     */
    public static function apply(): array
    {
        global $wpdb;

        $rows         = self::loadAttributeMetaRows();
        $repaired     = 0;
        $missingTerm  = 0;
        $touchedParents = [];

        foreach ($rows as $row) {
            $taxonomy = (string) ($row['taxonomy'] ?? '');
            $current  = (string) ($row['meta_value'] ?? '');
            if ($taxonomy === '' || $current === '') {
                continue;
            }
            $correctSlug = self::resolveCorrectSlug($taxonomy, $current);
            if ($correctSlug === null) {
                $missingTerm++;
                continue;
            }
            if ($correctSlug === $current) {
                continue;
            }

            $vid     = (int) $row['post_id'];
            $key     = (string) $row['meta_key'];
            $parent  = (int) $row['parent_id'];

            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => $correctSlug],
                ['post_id' => $vid, 'meta_key' => $key],
                ['%s'],
                ['%d', '%s']
            );
            $repaired++;
            $touchedParents[$parent] = true;

            // Drop the variation's own get_metadata cache so a same-request
            // wc_get_product($vid) sees the new value.
            \wp_cache_delete($vid, 'post_meta');
        }

        // Re-aggregate parents so the price range + lookup tables
        // (wc_product_meta_lookup) reflect the now-visible variations.
        // Without this Woo's frontend queries still return the stale
        // visible-variation set.
        if ($touchedParents && class_exists('\\WC_Product_Variable')) {
            foreach (array_keys($touchedParents) as $pid) {
                \wp_cache_delete($pid, 'post_meta');
                \wp_cache_delete($pid, 'posts');
                \WC_Product_Variable::sync((int) $pid);
            }
        }

        return [
            'repaired'        => $repaired,
            'missing_term'    => $missingTerm,
            'parents_touched' => count($touchedParents),
        ];
    }

    /**
     * Pull every attribute_pa_* meta row whose post is a variation,
     * with its parent_id pre-joined. One query for the whole catalogue;
     * MySQL handles the join on (post_id) + (post_type='product_variation')
     * with the standard WP indexes.
     *
     * @return array<int, array{post_id:int, parent_id:int, meta_key:string, meta_value:string, taxonomy:string}>
     */
    private static function loadAttributeMetaRows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT pm.post_id,
                   p.post_parent AS parent_id,
                   pm.meta_key,
                   pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type   = 'product_variation'
              AND p.post_status NOT IN ('trash','auto-draft')
              AND pm.meta_key LIKE 'attribute_pa\\_%'
        ", ARRAY_A);
        if (! is_array($rows)) return [];

        // Pre-derive taxonomy slug for each row so the inner loop is
        // pure-PHP comparisons + a memoized term lookup.
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            $tax = preg_replace('/^attribute_/', '', $key);
            if (! is_string($tax) || $tax === '') continue;
            $row['taxonomy'] = $tax;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Per-request memoized term resolution. Returns the canonical slug
     * for $value in $taxonomy, or null when no term matches by NAME
     * and the value isn't already a valid slug.
     */
    private static function resolveCorrectSlug(string $taxonomy, string $value): ?string
    {
        static $cache = [];
        $cacheKey = $taxonomy . '|' . $value;
        if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

        if (! \taxonomy_exists($taxonomy)) {
            return $cache[$cacheKey] = null;
        }

        // First: is the value ALREADY a valid slug? That's the no-op
        // case — most rows on a re-run hit here.
        $bySlug = \get_term_by('slug', $value, $taxonomy);
        if ($bySlug) {
            return $cache[$cacheKey] = $bySlug->slug;
        }

        // Else: lookup by NAME. The bridge bug stored raw feed values
        // (size names like "33.5") under attribute_pa_taglia instead
        // of the slug ("33-5"). get_term_by('name', ...) finds the
        // term and we read its slug.
        $byName = \get_term_by('name', $value, $taxonomy);
        if ($byName) {
            return $cache[$cacheKey] = $byName->slug;
        }

        // Last attempt: the value might be the sanitize_title form of
        // a known term name but the term was never created (rare —
        // would mean the parent product's pa_taglia term set is
        // incomplete). Return null so the caller logs as 'missing_term'
        // rather than silently rewriting to a nonexistent slug.
        return $cache[$cacheKey] = null;
    }

    private static function variationSku(int $vid): string
    {
        global $wpdb;
        $sku = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sku' LIMIT 1",
            $vid
        ));
        return is_string($sku) ? $sku : '';
    }
}
