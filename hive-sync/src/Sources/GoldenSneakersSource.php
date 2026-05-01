<?php
declare(strict_types=1);

namespace HiveSync\Sources;

use HiveSync\Core\Source\AbstractSource;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Core\Source\FetchResult;
use HiveSync\Core\Source\MaterializeResult;
use HiveSync\Core\Source\SourceCapabilities;

/**
 * Golden Sneakers source — delegating adapter.
 *
 * The legacy procedural module (rp_rc_gs_*, ~723 LOC in
 * golden-hive/includes/feeds/feed-goldensneakers.php) remains the source
 * of truth for fetch/normalize/transform/diff/create/update. This class
 * routes through three host filters (hsync_gs_fetch / _diff /
 * _materialize) so Hive Sync stays decoupled — when phase 5 demolishes
 * the legacy modules, those filter implementations move into
 * HiveSync\ without changing this class.
 *
 * Unlike duplicating GS code into Hive Sync, this approach has zero
 * regression risk: the proven legacy code keeps doing the work.
 */
final class GoldenSneakersSource extends AbstractSource
{
    public const ID = 'goldensneakers';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Golden Sneakers';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            canFetch: true,
            canDiff: true,
            canMaterialize: true,
            canSelectLocal: false,
            supportsQuickPatch: false,
            supportsImageSideload: true,
        );
    }

    public function configSchema(): array
    {
        // Mirrors the schema in golden-hive's feed-credentials.php.
        // The credentials store remains single-source-of-truth in the host
        // — Hive Sync's UI will hydrate redacted secrets via the host
        // adapter when the unified form is rendered (phase 3b).
        return [
            'url' => [
                'type'     => 'url',
                'label'    => 'API URL',
                'required' => true,
                'max'      => 4096,
            ],
            'token' => [
                'type'     => 'secret',
                'label'    => 'Bearer token',
                'required' => true,
                'max'      => 8192,
            ],
            'cookie' => [
                'type'     => 'secret',
                'label'    => 'Cookie',
                'required' => false,
                'max'      => 16384,
            ],
            'format' => [
                'type'     => 'enum',
                'label'    => 'Formato risposta',
                'required' => false,
                'options'  => ['hierarchical', 'flat'],
            ],
        ];
    }

    public function fetch(FetchRequest $request, Context $ctx): FetchResult
    {
        $val = $this->validateConfig($request->config);
        if (! $val['ok']) {
            return new FetchResult(
                items: [],
                stats: ['errors' => $val['errors']],
                warnings: ['Config invalida — verifica url/token.'],
            );
        }

        if (! function_exists('hsync_gs_fetch')) {
            return new FetchResult(items: [], warnings: ['Host adapter non caricato.']);
        }

        $resp = \hsync_gs_fetch($val['config'], $request->options);
        if ($resp === null) {
            return new FetchResult(items: [], warnings: ['Host GS non disponibile.']);
        }

        $items = [];
        foreach ((array) ($resp['items'] ?? []) as $row) {
            if (! is_array($row)) continue;
            $items[] = new FeedItem(
                sku: (string) ($row['sku'] ?? ''),
                data: (array) ($row['data'] ?? []),
                raw: (array) ($row['raw'] ?? []),
            );
        }

        return new FetchResult(
            items: $items,
            stats: (array) ($resp['stats'] ?? []),
            warnings: array_map('strval', (array) ($resp['warnings'] ?? [])),
        );
    }

    public function diff(array $items, Context $ctx): Diff
    {
        if (! function_exists('hsync_gs_diff')) {
            return new Diff();
        }

        $payload = array_map(
            static fn(FeedItem $i): array => $i->data,
            $items,
        );
        $resp = \hsync_gs_diff($payload);
        if ($resp === null) {
            return new Diff();
        }

        return new Diff(
            new: self::wrapBucket((array) ($resp['new'] ?? [])),
            update: self::wrapBucket((array) ($resp['update'] ?? [])),
            unchanged: self::wrapBucket((array) ($resp['unchanged'] ?? [])),
        );
    }

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult
    {
        if ($ctx->dryRun) {
            return MaterializeResult::skipped(null, 'dry_run');
        }
        if (! function_exists('hsync_gs_materialize')) {
            return MaterializeResult::failed('Host adapter non caricato.');
        }

        $sideload = (bool) ($ctx->meta['sideload'] ?? true);
        try {
            $r = \hsync_gs_materialize($item->data, false, $sideload);
        } catch (\Throwable $e) {
            return MaterializeResult::failed($e->getMessage());
        }
        if (! is_array($r)) {
            return MaterializeResult::failed('host_returned_non_array');
        }

        $action = (string) ($r['action'] ?? 'error');
        $pid    = (int) ($r['id'] ?? 0);

        if ($action === 'error' || $pid <= 0) {
            return MaterializeResult::failed(
                error: (string) ($r['reason'] ?? 'unknown_error'),
                productId: $pid > 0 ? $pid : null,
            );
        }

        return $action === 'updated'
            ? MaterializeResult::updated($pid, $r)
            : MaterializeResult::created($pid, $r);
    }

    /**
     * @param array<int, array> $bucket
     * @return FeedItem[]
     */
    private static function wrapBucket(array $bucket): array
    {
        $out = [];
        foreach ($bucket as $woo) {
            if (! is_array($woo)) continue;
            $out[] = new FeedItem(
                sku: (string) ($woo['sku'] ?? ''),
                data: $woo,
            );
        }
        return $out;
    }
}
