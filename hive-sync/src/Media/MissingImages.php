<?php
declare(strict_types=1);

namespace HiveSync\Media;

use HiveSync\Sources\MissingMediaLookup;

/**
 * Catalog-wide "which products are showing no image?" scan — the
 * read-only half of the Media tab's repair button.
 *
 * Deliberately feed-free. Answering "how bad is it?" must not require
 * downloading a multi-MB feed, picking a mapping, or writing anything:
 * the operator noticed the problem while looking at the media library
 * and wants a number, immediately. The repair (which DOES need the
 * feed, for the image URLs) is a separate, explicit second step.
 *
 * The broken-ness predicate is shared with MissingMediaLookup rather
 * than re-expressed here — see joinSql() / whereSql() there. A scan
 * that disagreed with the runner would promise to fix a set the runner
 * then declines to touch.
 *
 * Provenance breakdown: products carry `_gh_import_source` (hive-sync /
 * golden-hive feeds) or `_feed_source` (older imports). Grouping by it
 * is what turns a bare count into triage — "30 from goldensneakers"
 * are repairable from the feed, "17 with no source" were made by hand
 * and no import will ever fix them.
 */
final class MissingImages
{
    /** Products in these states aren't part of the live catalog. */
    private const EXCLUDED_STATUSES = "('trash','auto-draft')";

    /**
     * One provenance row per product, guaranteed.
     *
     * A plain `LEFT JOIN postmeta ON meta_key IN (a, b)` matches BOTH
     * keys when a product carries them, multiplying its row. That would
     * list the same product twice in the sample table and — when the
     * two keys disagree — count it under two different sources, so the
     * histogram would sum to more than the total it sits next to.
     * Collapsing to one row per post_id in a derived table removes the
     * multiplication at the source.
     */
    private static function sourceJoinSql(string $alias = 'p'): string
    {
        global $wpdb;
        return "
            LEFT JOIN (
                SELECT post_id, MIN(meta_value) AS meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key IN ('_gh_import_source', '_feed_source')
                  AND meta_value <> ''
                GROUP BY post_id
            ) src ON src.post_id = {$alias}.ID
        ";
    }

    /**
     * @return array{
     *   total: int,
     *   by_source: array<string, int>,
     *   sample: array<int, array{id:int, sku:string, name:string, status:string, source:string, edit_url:string}>,
     *   sample_capped: bool
     * }
     */
    public static function scan(int $sampleSize = 50): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [ 'total' => 0, 'by_source' => [], 'sample' => [], 'sample_capped' => false ];
        }

        $sampleSize = max(1, min(500, $sampleSize));
        $join       = MissingMediaLookup::joinSql('p');
        $where      = MissingMediaLookup::whereSql();
        $excluded   = self::EXCLUDED_STATUSES;

        // Parent products only. A `product_variation` has no featured
        // image of its own by design (it inherits the parent's), so
        // counting variations would report thousands of phantom breaks.
        $base = "
            FROM {$wpdb->posts} p
            {$join}
            WHERE p.post_type = 'product'
              AND p.post_status NOT IN {$excluded}
              AND {$where}
        ";

        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$base}");
        if ($total === 0) {
            return [ 'total' => 0, 'by_source' => [], 'sample' => [], 'sample_capped' => false ];
        }

        // Provenance histogram. LEFT JOIN so products with no source
        // meta still appear — they're the manually-created ones, and
        // they are exactly the rows the operator must not expect an
        // import to repair.
        $bySource = [];
        $rows = $wpdb->get_results("
            SELECT COALESCE(NULLIF(src.meta_value, ''), '') AS source, COUNT(DISTINCT p.ID) AS n
            FROM {$wpdb->posts} p
            {$join}
            " . self::sourceJoinSql('p') . "
            WHERE p.post_type = 'product'
              AND p.post_status NOT IN {$excluded}
              AND {$where}
            GROUP BY source
            ORDER BY n DESC
        ", ARRAY_A);
        foreach ((array) $rows as $row) {
            $src = (string) ($row['source'] ?? '');
            $bySource[$src === '' ? '(nessuna sorgente)' : $src] = (int) ($row['n'] ?? 0);
        }

        // Sample for the UI table. Newest first: a fresh import gone
        // wrong is the likeliest reason anyone is on this screen.
        $sampleRows = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID AS id, p.post_title AS name, p.post_status AS status,
                   sku.meta_value AS sku,
                   COALESCE(NULLIF(src.meta_value, ''), '') AS source
            FROM {$wpdb->posts} p
            {$join}
            LEFT JOIN {$wpdb->postmeta} sku
                   ON sku.post_id = p.ID AND sku.meta_key = '_sku'
            " . self::sourceJoinSql('p') . "
            WHERE p.post_type = 'product'
              AND p.post_status NOT IN {$excluded}
              AND {$where}
            ORDER BY p.ID DESC
            LIMIT %d
        ", $sampleSize), ARRAY_A);

        $sample = [];
        foreach ((array) $sampleRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $sample[] = [
                'id'       => $id,
                'sku'      => (string) ($row['sku']    ?? ''),
                'name'     => (string) ($row['name']   ?? ''),
                'status'   => (string) ($row['status'] ?? ''),
                'source'   => (string) ($row['source'] ?? ''),
                'edit_url' => function_exists('get_edit_post_link')
                    ? (string) \get_edit_post_link($id, '')
                    : '',
            ];
        }

        return [
            'total'         => $total,
            'by_source'     => $bySource,
            'sample'        => $sample,
            'sample_capped' => $total > count($sample),
        ];
    }

    /**
     * Pick which configured import job the repair should run through.
     *
     * The Media tab has no source context — it's a view over the WP
     * media library — but repairing needs the feed. Rather than making
     * the operator re-pick source + config + mapping + pipeline (and
     * risk choosing differently from their real import), reuse a job:
     * a job row already bundles exactly that quadruple, and stays in
     * sync with the import as they edit it.
     *
     * Returns the sole enabled import job when there's exactly one —
     * the common install, where the picker is pure friction. Otherwise
     * null, and the UI asks.
     *
     * Pure: takes rows, returns a row. Unit-testable without WP.
     *
     * @param array<int, array<string, mixed>> $jobs JobRepository::all()
     * @return array<string, mixed>|null
     */
    public static function pickDefaultJob(array $jobs): ?array
    {
        $imports = self::importJobs($jobs);
        if (count($imports) === 1) return $imports[0];

        $enabled = array_values(array_filter($imports, static fn(array $j): bool => ! empty($j['enabled'])));
        return count($enabled) === 1 ? $enabled[0] : null;
    }

    /**
     * The source.import subset, in a shape the UI can render as options.
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    public static function importJobs(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            if (! is_array($job)) continue;
            if ((string) ($job['runnable_type'] ?? '') !== 'source.import') continue;
            $ref = (string) ($job['runnable_ref'] ?? '');
            if (! str_contains($ref, '/')) continue;
            $out[] = $job;
        }
        return $out;
    }
}
