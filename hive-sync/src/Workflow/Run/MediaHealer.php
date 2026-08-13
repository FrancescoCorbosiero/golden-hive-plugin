<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Sources\MissingMediaLookup;

/**
 * Media-aware re-bucketing: pulls products that exist in Woo WITHOUT a
 * usable featured image out of `unchanged` / `updateStock` and into
 * `update`, so the full pipeline (media.download + the bridge's heal
 * branch) runs against exactly the broken ones.
 *
 * ─── Why a normal re-sync can't fix a missing image ──────────────────
 *
 * The bridge already knows how to heal: rp_rc_gs_image_update_action
 * returns 'attach' when a product has no featured image and the feed
 * carries a usable URL. But that decision lives inside
 * rp_rc_gs_update_product, which only runs for items in the `update`
 * bucket — and the diff never puts a broken product there:
 *
 *   - StockOnlyClassifier::split() compares name / description /
 *     status / price / stock. Never media.
 *   - CsvSource::sfProductNeedsUpdate() compares price + stock only.
 *   - `updateStock` items skip materialize entirely (fastStockPatch).
 *
 * So a product imported during a window of broken image URLs matches
 * the feed on every field the diff inspects, forever. It sits in
 * `unchanged` and no amount of re-syncing touches it. That is the
 * "impossible to re-populate" symptom.
 *
 * ─── Why not force_recreate ──────────────────────────────────────────
 *
 * force_recreate is the existing escape hatch, but it is all-or-nothing
 * across the whole feed and it discards every variation (variation ids
 * change, so anything keyed on them is invalidated). Rewriting 5k
 * products to repair 30 images is the wrong trade. This heals only the
 * products that are actually broken, through the ordinary idempotent
 * update path, leaving variations alone.
 *
 * ─── The perpetual-work guard ────────────────────────────────────────
 *
 * A product is only re-bucketed when the FEED can actually fix it —
 * i.e. Source::imageUrls() yields at least one usable URL for that
 * item. Without this, products the upstream genuinely has no image for
 * would re-enter the processing pool on every single run and never
 * converge: the heal can't succeed, so they'd stay imageless and get
 * picked up again next tick, forever. With it, the operation is
 * self-terminating — once an image attaches (or if the feed has none
 * to give), the item drops back to `unchanged` and stays there.
 */
final class MediaHealer
{
    /**
     * Stamped on every item this class moves into `update`. Lets the
     * runner tell "queued because the feed changed" from "queued to
     * repair a missing image" — needed when a bucket-restricted job
     * (e.g. buckets=['updateStock']) enables the heal: the healed items
     * must still run, without dragging in the unrelated `update` items
     * that job deliberately ignores.
     */
    public const MARKER = '_hsync_healed_media';

    /**
     * Re-bucket the diff. Pure w.r.t. WordPress: both the feed-side
     * image lookup and the Woo-side "is it broken" query arrive as
     * callables, so the whole decision matrix is unit-testable.
     *
     * @param Diff                          $diff
     * @param callable(FeedItem): string[]  $imageUrlsOf Feed image URLs for an item.
     * @param callable(int[]): int[]        $findBroken  Subset of ids lacking a featured image.
     * @return array{diff: Diff, healed: int}
     */
    public static function rebucket(Diff $diff, callable $imageUrlsOf, callable $findBroken): array
    {
        // Only these two buckets are eligible: `new` has no product yet,
        // and `update` already routes through materialize (it heals on
        // its own). Keyed by name so the rebuild below stays symmetric.
        $eligible = [
            'unchanged'   => array_values($diff->unchanged),
            'updateStock' => array_values($diff->updateStock),
        ];

        // 1. Candidates = items the diff decided not to send through
        //    materialize, that map to a real product, AND that the feed
        //    could actually repair. Filtering on the feed side FIRST
        //    keeps the SQL below scoped to ids worth asking about.
        $candidates = [];   // pid => [bucket, index]
        foreach ($eligible as $bucket => $items) {
            foreach ($items as $idx => $item) {
                if (! $item instanceof FeedItem) continue;
                $pid = (int) ($item->data['_existing_id'] ?? 0);
                if ($pid <= 0) continue;
                // Already claimed by the other bucket — first wins, and
                // the loser is left in place (it would be a duplicate
                // write of the same product otherwise).
                if (isset($candidates[$pid])) continue;
                $urls = $imageUrlsOf($item);
                if (! is_array($urls) || $urls === []) continue;
                $candidates[$pid] = [ $bucket, $idx ];
            }
        }
        if (! $candidates) {
            return [ 'diff' => $diff, 'healed' => 0 ];
        }

        // 2. Ask Woo which of those are actually missing their image.
        $broken = $findBroken(array_keys($candidates));
        if (! is_array($broken) || $broken === []) {
            return [ 'diff' => $diff, 'healed' => 0 ];
        }

        // 3. Move them. Rebuilding the arrays (rather than unset+merge)
        //    keeps the buckets list-shaped — the runner indexes them
        //    positionally for the resume cursor.
        $move = [];   // bucket => [idx => true]
        foreach ($broken as $pid) {
            $pid = (int) $pid;
            if (! isset($candidates[$pid])) continue;
            [ $bucket, $idx ] = $candidates[$pid];
            $move[$bucket][$idx] = true;
        }
        if (! $move) {
            return [ 'diff' => $diff, 'healed' => 0 ];
        }

        $update  = $diff->update;
        $healed  = 0;
        $rebuilt = [ 'unchanged' => [], 'updateStock' => [] ];
        foreach ($eligible as $bucket => $items) {
            foreach ($items as $idx => $item) {
                if (isset($move[$bucket][$idx])) {
                    // FeedItem is readonly — rebuild to carry the marker.
                    $update[] = new FeedItem(
                        sku:  $item->sku,
                        data: $item->data + [ self::MARKER => true ],
                        raw:  $item->raw,
                    );
                    $healed++;
                    continue;
                }
                $rebuilt[$bucket][] = $item;
            }
        }

        return [
            'diff' => new Diff(
                new:         $diff->new,
                update:      $update,
                unchanged:   $rebuilt['unchanged'],
                updateStock: $rebuilt['updateStock'],
            ),
            'healed' => $healed,
        ];
    }

    /**
     * Production wiring: feed URLs from the Source, broken-image ids
     * from a batched SQL lookup.
     *
     * @return array{diff: Diff, healed: int}
     */
    public static function forSource(Diff $diff, \HiveSync\Core\Source\Source $source): array
    {
        return self::rebucket(
            $diff,
            static fn(FeedItem $item): array => $source->imageUrls($item),
            static fn(array $ids): array => MissingMediaLookup::withoutFeaturedImage($ids),
        );
    }
}
