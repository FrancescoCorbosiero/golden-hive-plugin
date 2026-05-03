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
 * Generic CSV source — native PHP parser, no Golden Hive dependency.
 *
 * Accepts a CSV either by URL or local file path. Column→Woo-field
 * mapping is provided via FetchRequest::options['mapping'] (a Mapping
 * row, persisted independently in wp_hsync_mappings). The mapping
 * names which columns become sku/name/regular_price/etc.; rows missing
 * required columns are dropped with a per-row warning.
 *
 * Materialize delegates to the host adapter (`hsync_upsert_product`)
 * which Golden Hive's bridge wires to gh_create_simple_product.
 */
final class CsvSource extends AbstractSource
{
    public const ID = 'csv';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'CSV — Feed da URL o file (StockFirmati, ecc.)';
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
        return [
            'source_type' => [
                'type'     => 'enum',
                'label'    => 'Sorgente',
                'options'  => ['url', 'file'],
                'default'  => 'url',
                'required' => true,
            ],
            'url' => [
                'type'  => 'url',
                'label' => 'URL CSV (se source_type=url)',
                'max'   => 4096,
            ],
            'file_path' => [
                'type'  => 'text',
                'label' => 'Path file (se source_type=file)',
                'max'   => 4096,
            ],
            'delimiter' => [
                'type'    => 'enum',
                'label'   => 'Delimitatore',
                'options' => [',', ';', "\t", '|'],
                'default' => ',',
            ],
            'enclosure' => [
                'type'    => 'text',
                'label'   => 'Enclosure',
                'default' => '"',
                'max'     => 1,
            ],
            'has_header' => [
                'type'    => 'bool',
                'label'   => 'Prima riga = header',
                'default' => true,
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
                warnings: ['Config invalida.'],
            );
        }
        $cfg = $val['config'];

        $sourceType = (string) ($cfg['source_type'] ?? 'url');
        $contents = $sourceType === 'file'
            ? self::readFile((string) ($cfg['file_path'] ?? ''))
            : self::readUrl((string) ($cfg['url'] ?? ''));

        if ($contents === null) {
            return new FetchResult(items: [], warnings: ['Lettura CSV fallita.']);
        }

        $delimiter = (string) ($cfg['delimiter'] ?? ',');
        $enclosure = (string) ($cfg['enclosure'] ?? '"');
        $hasHeader = (bool) ($cfg['has_header'] ?? true);
        $mapping   = (array) ($request->options['mapping'] ?? []);

        $rows = self::parseCsv($contents, $delimiter, $enclosure);
        if (! $rows) {
            return new FetchResult(items: [], warnings: ['CSV vuoto o non leggibile.']);
        }

        $header = $hasHeader ? array_shift($rows) : null;
        $items = [];
        $warnings = [];

        // Optional per-fetch category filter — used by SF "subset" jobs
        // where each scheduled job processes only the rows that fall
        // into a given category set (so each subset can carry its own
        // markup downstream). Match is case-insensitive substring on
        // the mapped `categories` field; empty filter = pass all.
        $catFilter = self::normalizeCategoryFilter($request->options['category_filter'] ?? null);
        $skippedByFilter = 0;

        foreach ($rows as $rowIdx => $row) {
            $assoc = $header !== null
                ? self::associate($header, $row)
                : $row;
            $mapped = self::applyMapping($assoc, $mapping);

            $sku = (string) ($mapped['sku'] ?? '');
            if ($sku === '') {
                $warnings[] = "Riga " . ( $rowIdx + 1 ) . ": SKU mancante, scartata.";
                continue;
            }
            if ($catFilter !== [] && ! self::rowMatchesCategoryFilter($mapped, $assoc, $catFilter)) {
                $skippedByFilter++;
                continue;
            }
            $items[] = new FeedItem(sku: $sku, data: $mapped, raw: $assoc);
        }

        $stats = ['rows_parsed' => count($items), 'rows_skipped' => count($warnings)];
        if ($skippedByFilter > 0) {
            $stats['rows_filtered'] = $skippedByFilter;
            $warnings[] = "Filtrati {$skippedByFilter} prodotti fuori dalle categorie selezionate.";
        }

        return new FetchResult(
            items: $items,
            stats: $stats,
            warnings: $warnings,
        );
    }

    /**
     * @param mixed $raw
     * @return string[]  lowercase trimmed list — empty list = "no filter"
     */
    private static function normalizeCategoryFilter($raw): array
    {
        if (is_string($raw)) {
            $raw = str_contains($raw, ',') ? explode(',', $raw) : [$raw];
        }
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            if (! is_scalar($v)) continue;
            $v = strtolower(trim((string) $v));
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    /**
     * Check the mapped row against the category allowlist. Looks at the
     * `categories` mapped field first; falls back to `category` /
     * `product_cat` / a raw column matching `cat`/`category`.
     *
     * @param string[] $filter
     */
    private static function rowMatchesCategoryFilter(array $mapped, array $raw, array $filter): bool
    {
        $candidates = [];
        foreach (['categories', 'category', 'product_cat'] as $k) {
            $v = $mapped[$k] ?? null;
            if (is_string($v))      $candidates = array_merge($candidates, str_contains($v, '|') ? explode('|', $v) : [$v]);
            elseif (is_array($v))   $candidates = array_merge($candidates, $v);
        }
        // Last-resort: scan raw row for any column whose name LOOKS LIKE
        // a category. Useful when the mapping doesn't expose category.
        if (empty($candidates)) {
            foreach ($raw as $k => $v) {
                if (! is_string($k) || ! is_scalar($v)) continue;
                $kl = strtolower($k);
                if (str_contains($kl, 'categ') || $kl === 'cat') $candidates[] = (string) $v;
            }
        }
        foreach ($candidates as $c) {
            $cl = strtolower(trim((string) $c));
            if ($cl === '') continue;
            foreach ($filter as $f) {
                if ($cl === $f || str_contains($cl, $f)) return true;
            }
        }
        return false;
    }

    public function diff(array $items, Context $ctx): Diff
    {
        $new = $update = $unchanged = [];
        $skuLookup = function_exists('wc_get_product_id_by_sku');

        foreach ($items as $item) {
            if (! $item instanceof FeedItem) continue;
            if ($item->sku === '') {
                $new[] = $item;
                continue;
            }
            $existingId = $skuLookup ? (int) \wc_get_product_id_by_sku($item->sku) : 0;
            if ($existingId > 0) {
                $update[] = new FeedItem(
                    sku: $item->sku,
                    data: $item->data + ['_existing_id' => $existingId],
                    raw: $item->raw,
                );
            } else {
                $new[] = $item;
            }
        }

        // Split `update` into full vs stock-only so a job tagged
        // buckets:['updateStock'] gets the cheap path. SF "refresh"
        // jobs rely on this to stay sub-second per product.
        [ $updateFull, $updateStock ] = StockOnlyClassifier::split( $update );

        return new Diff(
            new: $new,
            update: $updateFull,
            unchanged: $unchanged,
            updateStock: $updateStock,
        );
    }

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult
    {
        if ($ctx->dryRun) {
            return MaterializeResult::skipped(null, 'dry_run');
        }
        if (! function_exists('hsync_upsert_product')) {
            return MaterializeResult::failed('host adapter non caricato (product/upsert).');
        }

        try {
            $pid = \hsync_upsert_product(
                $item->data,
                ['source' => self::ID, 'context' => $ctx->meta],
            );
        } catch (\Throwable $e) {
            return MaterializeResult::failed($e->getMessage());
        }

        if ($pid === null || $pid <= 0) {
            return MaterializeResult::failed('host_upsert_returned_null');
        }

        $existing = (int) ($item->data['_existing_id'] ?? 0);
        $action = $existing > 0 ? 'updated' : 'created';

        $this->recordProvenance($pid, self::ID, [
            'catalog' => self::ID,
            'pricing' => self::ID,
            'stock'   => self::ID,
        ]);

        return $action === 'updated'
            ? MaterializeResult::updated($pid, ['sku' => $item->sku])
            : MaterializeResult::created($pid, ['sku' => $item->sku]);
    }

    // ─── CSV parsing helpers (pure PHP, unit-testable) ─────────────

    private static function readUrl(string $url): ?string
    {
        if ($url === '') return null;
        if (function_exists('wp_remote_get')) {
            $r = \wp_remote_get($url, ['timeout' => 30]);
            if (function_exists('is_wp_error') && \is_wp_error($r)) return null;
            $body = \wp_remote_retrieve_body($r);
            return is_string($body) && $body !== '' ? $body : null;
        }
        $contents = @file_get_contents($url);
        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private static function readFile(string $path): ?string
    {
        if ($path === '' || ! is_readable($path)) return null;
        $contents = @file_get_contents($path);
        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Parse a CSV body string into rows. Pure PHP — no fopen/fclose
     * needed thanks to str_getcsv per line.
     *
     * @return array<int, array<int, string>>
     */
    public static function parseCsv(string $body, string $delimiter, string $enclosure): array
    {
        $body = preg_replace("/\r\n|\r/", "\n", $body);
        if ($body === null) return [];
        $lines = explode("\n", $body);
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $row = str_getcsv($line, $delimiter, $enclosure, '\\');
            if ($row !== [null]) $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string, string> */
    public static function associate(array $header, array $row): array
    {
        $assoc = [];
        foreach ($header as $i => $col) {
            $key = is_string($col) ? trim($col) : (string) $i;
            $assoc[$key] = isset($row[$i]) ? (string) $row[$i] : '';
        }
        return $assoc;
    }

    /**
     * Apply mapping to a row. Each mapping value is one of:
     *
     *   - direct field name        e.g. 'sku' → row['sku']
     *   - dot-path                 e.g. 'sizes.size_eu' → traverses + fans out
     *                              over indexed sub-arrays (returns array)
     *   - template string          e.g. '<p>{brand_name} {name}</p>' → rendered
     *                              against the row, supporting placeholder paths
     *
     * Detection: a value containing a `{...}` chunk is a template;
     * otherwise it's a path.
     */
    public static function applyMapping( array $row, array $mapping ): array
    {
        if ( ! $mapping ) return $row;
        $out = [];
        foreach ( $mapping as $target => $source ) {
            $sourceStr = (string) $source;
            $key       = (string) $target;
            if ( \HiveSync\Workflow\Mapping\Template::isTemplate( $sourceStr ) ) {
                $out[ $key ] = \HiveSync\Workflow\Mapping\Template::render( $sourceStr, $row );
                continue;
            }
            // Plain top-level key wins (preserves CSV-style flat semantics).
            if ( ! str_contains( $sourceStr, '.' ) && array_key_exists( $sourceStr, $row ) ) {
                $out[ $key ] = $row[ $sourceStr ];
                continue;
            }
            // Otherwise treat as a dot-path traversal.
            $resolved = \HiveSync\Workflow\Mapping\PathResolver::resolve( $row, $sourceStr );
            if ( $resolved !== null ) $out[ $key ] = $resolved;
        }
        return $out;
    }
}
