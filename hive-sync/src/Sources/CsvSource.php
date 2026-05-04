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
                'option_labels' => [
                    ','  => 'Virgola  ,',
                    ';'  => 'Punto e virgola  ;',
                    "\t" => 'Tab',
                    '|'  => 'Pipe  |',
                ],
                'default' => ',',
                'description' => 'Quale carattere separa le colonne nel file CSV. La maggior parte dei feed italiani usa "; (punto e virgola)". Se l\'anteprima ritorna 0 righe, di solito è qui che bisogna intervenire.',
            ],
            'enclosure' => [
                'type'    => 'text',
                'label'   => 'Enclosure (carattere virgolette)',
                'default' => '"',
                'max'     => 1,
                'description' => 'Carattere usato per racchiudere i valori che contengono il delimitatore (es. virgolette doppie ").',
            ],
            'has_header' => [
                'type'    => 'bool',
                'label'   => 'Prima riga = intestazione (nomi colonne)',
                'default' => true,
                'description' => 'Lascialo attivo se la prima riga del CSV contiene i nomi delle colonne (es. "SKU,RECORD_TYPE,BRAND,...").',
            ],
            'flavor' => [
                'type'    => 'enum',
                'label'   => 'Formato del feed CSV',
                'options' => [ 'generic', 'stockfirmati' ],
                'option_labels' => [
                    'generic'      => 'Generico — 1 riga CSV = 1 prodotto',
                    'stockfirmati' => 'StockFirmati — RECORD_TYPE PRODUCT + MODEL (raggruppa per SKU)',
                ],
                'default' => 'generic',
                'description' => 'Lascia "Generico" se il tuo CSV è una lista normale di prodotti. Scegli "StockFirmati" SOLO per il feed SF, che ha due tipi di riga: PRODUCT (master) e MODEL (varianti per taglia).',
            ],
            'sf_markup_mode' => [
                'type'    => 'enum',
                'label'   => 'SF: come applicare il markup',
                'options' => [ 'multiplier', 'percent' ],
                'option_labels' => [
                    'multiplier' => 'Moltiplicatore (cost × N)',
                    'percent'    => 'Percentuale (+N% sul cost)',
                ],
                'default' => 'multiplier',
                'description' => 'Solo per il feed StockFirmati. Determina come ricavare il prezzo di vendita dal cost wholesale.',
            ],
            'sf_markup_value' => [
                'type'    => 'text',
                'label'   => 'SF: valore markup',
                'default' => '3.5',
                'max'     => 16,
                'description' => 'Esempi: "3.5" se moltiplicatore (cost × 3.5), "250" se percentuale (+250%, equivalente). Decimali con virgola o punto (3,5 o 3.5).',
            ],
            'import_status' => [
                'type'    => 'enum',
                'label'   => 'Stato iniziale dei prodotti importati',
                'options' => [ 'publish', 'draft' ],
                'option_labels' => [
                    'publish' => 'Pubblicato — visibile sul sito appena importato',
                    'draft'   => 'Bozza — invisibile finché non lo pubblichi (serve una Regola)',
                ],
                'default' => 'publish',
                'description' => 'Imposta "Bozza" se vuoi importare in staging e poi attivare i prodotti a gruppi (per categoria/brand) tramite una Regola periodica nel tab Regole. Si applica solo ai NUOVI prodotti — quelli esistenti mantengono il loro stato.',
            ],
        ];
    }

    public const FLAVOR_GENERIC = 'generic';
    public const FLAVOR_SF      = 'stockfirmati';

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
        $flavor    = (string) ($cfg['flavor']     ?? self::FLAVOR_GENERIC);
        $mapping   = (array) ($request->options['mapping'] ?? []);

        $bodyLen = strlen($contents);
        $rows = self::parseCsv($contents, $delimiter, $enclosure);
        if (! $rows) {
            return new FetchResult(
                items: [],
                stats: ['body_bytes' => $bodyLen],
                warnings: [
                    'CSV non parsabile (' . $bodyLen . ' bytes scaricati). '
                    . 'Controlla delimitatore (virgola / punto e virgola / tab / pipe) ed enclosure.',
                ],
            );
        }

        // Detect probable wrong-delimiter symptom: every row has 1 cell.
        // When a delimiter mismatch happens (e.g. file uses ; but config
        // says ,), str_getcsv collapses each row into a single string.
        // Surface this loudly because it's the #1 reason fetches return
        // 0 items.
        $diagnostic = self::diagnoseCsvShape($rows, $delimiter, $bodyLen);

        $header = $hasHeader ? array_shift($rows) : null;

        // Stock-Firmati flavor short-circuits the per-row mapping path:
        // the CSV format is two-record-types (PRODUCT + MODEL) and the
        // grouping by SKU + variant assembly is handled inline. Then
        // the normal mapping (if any) runs as an overlay on top.
        // Honor the import_status knob — same semantics as JsonSource:
        // applied on CREATE only (existing products keep their state).
        $importStatus = (string) ($cfg['import_status'] ?? 'publish');
        if (! in_array($importStatus, ['publish', 'draft'], true)) {
            $importStatus = 'publish';
        }

        if ($flavor === self::FLAVOR_SF) {
            $assocRows = [];
            foreach ($rows as $row) {
                $assocRows[] = $header !== null ? self::associate($header, $row) : $row;
            }
            $multiplier = self::resolveSfMarkup($cfg);
            $items = self::sfNormalizeAndTransform($assocRows, $multiplier, $importStatus);
            $items = self::applySfCategoryFilter($items, $request->options['category_filter'] ?? null);
            if ($mapping) {
                $remapped = [];
                foreach ($items as $item) {
                    $mappedFields = self::applyMapping($item->data, $mapping);
                    $newData = $mappedFields + $item->data;
                    $newData['sku'] = $item->sku;
                    $remapped[] = new FeedItem(sku: $item->sku, data: $newData, raw: $item->raw);
                }
                $items = $remapped;
            }

            // SF-specific diagnostics: count PRODUCT vs MODEL records
            // so the operator knows where parsing actually broke down
            // (no rows / no PRODUCT records / records but no SKU / etc.).
            $sfDiag = self::diagnoseSfRows($assocRows, $header);
            $warnings = array_filter([ $diagnostic, $sfDiag ]);
            if (count($items) === 0 && ! $warnings) {
                $warnings[] = 'Nessun prodotto SF dopo aggregazione. Verifica colonne RECORD_TYPE / SKU / MODEL_SIZE.';
            }
            return new FetchResult(
                items: $items,
                stats: [
                    'body_bytes' => $bodyLen,
                    'flat_rows'  => count($assocRows),
                    'products'   => count($items),
                    'flavor'     => 'stockfirmati',
                    'header_columns' => $header !== null ? count($header) : 0,
                ],
                warnings: array_values($warnings),
            );
        }

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
            // Generic flavor: stamp the import_status default so the
            // bridge picks it up on create. Mapped configs that
            // explicitly set `status` win.
            if (! isset($mapped['status']) || $mapped['status'] === '') {
                $mapped['status'] = $importStatus;
            }
            $items[] = new FeedItem(sku: $sku, data: $mapped, raw: $assoc);
        }

        $stats = [
            'body_bytes'      => $bodyLen,
            'rows_parsed'     => count($items),
            'rows_skipped'    => count($warnings),
            'header_columns'  => $header !== null ? count($header) : 0,
        ];
        if ($skippedByFilter > 0) {
            $stats['rows_filtered'] = $skippedByFilter;
            $warnings[] = "Filtrati {$skippedByFilter} prodotti fuori dalle categorie selezionate.";
        }
        // Surface the shape diagnostic FIRST in the warnings list so
        // it shows up at the top of the test-fetch output.
        if ($diagnostic !== '') array_unshift($warnings, $diagnostic);

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

        // SF flavor routes through the legacy bridge (same pattern as
        // JsonSource's goldensneakers flavor). The bridge's
        // gh_sf_create_product / gh_sf_update_product handle brand +
        // category + media + variant updates that the generic
        // hsync_upsert_product can't currently do for existing variables.
        if (! empty($item->data['_hsync_flavor']) && $item->data['_hsync_flavor'] === self::FLAVOR_SF) {
            if (! function_exists('hsync_sf_materialize')) {
                return MaterializeResult::failed('Bridge SF non disponibile (host adapter).');
            }
            $sideload = (bool) ($ctx->meta['sideload'] ?? true);
            try {
                $r = \hsync_sf_materialize($item->data, false, $sideload);
            } catch (\Throwable $e) {
                return MaterializeResult::failed($e->getMessage());
            }
            return self::interpretBridgeResponse($r);
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

    // ─── Stock-Firmati flavor helpers ────────────────────────────
    //
    // Port of golden-hive's gh_sf_normalize + gh_sf_transform_to_woo.
    // SF CSV has two RECORD_TYPEs sharing one SKU:
    //   - PRODUCT row → master fields (brand, name, prices, images)
    //   - MODEL row   → one variant (size + per-size quantity + barcode)
    //
    // Output: one FeedItem per SKU, data already in the
    // (type / attributes / variations) shape the bridge's gh_sf_create
    // /update_product expects.

    /**
     * @param array<int, array<string, mixed>> $assocRows
     * @return FeedItem[]
     */
    private static function sfNormalizeAndTransform(array $assocRows, float $multiplier, string $importStatus = 'publish'): array
    {
        $products = [];
        foreach ($assocRows as $row) {
            $type = strtoupper(trim((string) ($row['RECORD_TYPE'] ?? '')));
            if ($type !== 'PRODUCT') continue;
            $sku = trim((string) ($row['SKU'] ?? $row['ORDERCODE'] ?? ''));
            if ($sku === '') continue;
            $products[$sku] = [
                'sku'             => $sku,
                'ordercode'       => trim((string) ($row['ORDERCODE'] ?? $sku)),
                'product_id'      => trim((string) ($row['PRODUCT_ID'] ?? '')),
                'brand'           => self::sfClean((string) ($row['BRAND']      ?? '')),
                'model_name'      => self::sfClean((string) ($row['MODEL_NAME'] ?? '')),
                'name'            => self::sfClean((string) ($row['Titel_ITA']       ?? '')),
                'description'     => self::sfClean((string) ($row['Description_ITA'] ?? '')),
                'street_price'    => (float) ($row['STREET_PRICE'] ?? 0),
                'cost_price'      => (float) ($row['PRICE']        ?? 0),
                'weight'          => (float) ($row['WEIGHT']       ?? 0),
                'images'          => array_values(array_filter([
                    trim((string) ($row['PICTURE_1'] ?? '')),
                    trim((string) ($row['PICTURE_2'] ?? '')),
                    trim((string) ($row['PICTURE_3'] ?? '')),
                ])),
                'sex'             => self::sfClean((string) ($row['SEX']      ?? '')),
                'category'        => self::sfClean((string) ($row['CAT']      ?? '')),
                'subcategory'     => self::sfClean((string) ($row['SUBCAT']   ?? '')),
                'color_code'      => trim((string) ($row['COLOR_CODE'] ?? '')),
                'color'           => self::sfClean((string) ($row['COLOR']    ?? '')),
                'material'        => self::sfClean((string) ($row['MATERIAL'] ?? '')),
                'made_in'         => trim((string) ($row['MADE_IN']  ?? '')),
                'season'          => trim((string) ($row['STAGIONE'] ?? '')),
                'source_url'      => trim((string) ($row['Product_url'] ?? '')),
                'sizes'           => [],
                '_raw_rows'       => [ $row ],
            ];
        }

        // Second pass: MODEL rows become variants of the matching SKU.
        foreach ($assocRows as $row) {
            $type = strtoupper(trim((string) ($row['RECORD_TYPE'] ?? '')));
            if ($type !== 'MODEL') continue;
            $parentSku = trim((string) ($row['SKU'] ?? ''));
            if ($parentSku === '' || ! isset($products[$parentSku])) continue;
            $size = self::sfClean((string) ($row['MODEL_SIZE'] ?? ''));
            if ($size === '') continue;
            $products[$parentSku]['sizes'][] = [
                'size'     => $size,
                'quantity' => (int) ($row['QUANTITY'] ?? 0),
                'barcode'  => trim((string) ($row['BARCODE'] ?? '')),
                'ean'      => trim((string) ($row['EAN']     ?? '')),
                'model_id' => trim((string) ($row['MODEL_ID'] ?? '')),
                'price'    => (float) ($row['PRICE'] ?? $products[$parentSku]['cost_price']),
            ];
            $products[$parentSku]['_raw_rows'][] = $row;
        }

        $items = [];
        foreach ($products as $sku => $p) {
            $rawRows = $p['_raw_rows'];
            unset($p['_raw_rows']);
            $woo = self::sfTransformToWoo($p, $multiplier, $importStatus);
            $woo['_hsync_flavor'] = self::FLAVOR_SF;
            $items[] = new FeedItem(sku: (string) $sku, data: $woo, raw: $rawRows);
        }
        return $items;
    }

    /**
     * Port of gh_sf_transform_to_woo. Produces the exact shape the
     * legacy bridge's gh_sf_create_product / gh_sf_update_product
     * expect: type + status + meta (`_sf_*`) + variations[].
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private static function sfTransformToWoo(array $product, float $multiplier, string $importStatus = 'publish'): array
    {
        $sizes    = $product['sizes'] ?? [];
        $hasSizes = count($sizes) > 0;
        $type     = $hasSizes ? 'variable' : 'simple';

        $streetPrice = (float) ($product['street_price'] ?? 0);
        $costPrice   = (float) ($product['cost_price']   ?? 0);
        $salePrice   = round($costPrice * $multiplier);
        $regPrice    = round($streetPrice);

        $name = ($product['name'] !== '')
            ? $product['name']
            : trim(($product['brand'] ?? '') . ' ' . ($product['model_name'] ?? ''));

        $woo = [
            'name'              => $name,
            'sku'               => $product['sku'],
            'type'              => $type,
            'status'             => $importStatus,
            'description'       => $product['description'] ?? '',
            'weight'            => ($product['weight'] ?? 0) > 0 ? (string) $product['weight'] : '',
            // SF-specific meta read by the legacy bridge for taxonomy +
            // image sideload during create/update.
            '_sf_brand'         => $product['brand']       ?? '',
            '_sf_category'      => $product['category']    ?? '',
            '_sf_subcategory'   => $product['subcategory'] ?? '',
            '_sf_sex'           => $product['sex']         ?? '',
            '_sf_color'         => $product['color']       ?? '',
            '_sf_material'      => $product['material']    ?? '',
            '_sf_made_in'       => $product['made_in']     ?? '',
            '_sf_season'        => $product['season']      ?? '',
            '_sf_images'        => $product['images']      ?? [],
            '_sf_source_url'    => $product['source_url']  ?? '',
            '_sf_cost_price'    => $costPrice,
        ];

        if ($type === 'simple') {
            $woo['regular_price']  = (string) $regPrice;
            $woo['sale_price']     = $salePrice > 0 ? (string) $salePrice : '';
            $woo['manage_stock']   = true;
            // No size = total stock from the PRODUCT row (we don't have
            // it explicitly but the legacy code summed sizes which here
            // is empty). Default to 0 — adjust manually if needed.
            $woo['stock_quantity'] = 0;
            $woo['stock_status']   = 'outofstock';
            $brand = (string) ($product['brand'] ?? '');
            if ($brand !== '') {
                $woo['attributes'] = [
                    'pa_brand' => ['options' => [$brand], 'visible' => true, 'variation' => false],
                ];
            }
            return $woo;
        }

        $allSizes = array_column($sizes, 'size');
        $woo['attributes'] = [
            'pa_taglia' => [
                'options'   => array_values(array_unique($allSizes)),
                'visible'   => true,
                'variation' => true,
            ],
        ];
        // Brand as a non-variation taxonomy attribute so the front-end
        // attribute filter can target it (Woo's product_brand alone
        // covers Woo Brands plugin filters; pa_brand covers the
        // built-in attribute filter widget). Mirrors JsonSource's
        // approach so SF and GS imports look identical in admin.
        $brand = (string) ($product['brand'] ?? '');
        if ($brand !== '') {
            $woo['attributes']['pa_brand'] = [
                'options'   => [$brand],
                'visible'   => true,
                'variation' => false,
            ];
        }

        $variations = [];
        $totalQty   = 0;
        foreach ($sizes as $size) {
            $varCost   = (float) ($size['price']    ?: $costPrice);
            $varSale   = round($varCost * $multiplier);
            $varReg    = round($streetPrice);
            $qty       = (int) ($size['quantity'] ?? 0);
            $totalQty += $qty;
            $variations[] = [
                'attributes'     => [ 'pa_taglia' => (string) $size['size'] ],
                'sku'            => (string) $product['sku'] . '-' . self::sfSlug((string) $size['size']),
                'regular_price'  => (string) $varReg,
                'sale_price'     => $varSale > 0 ? (string) $varSale : '',
                'manage_stock'   => true,
                'stock_quantity' => $qty,
                'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
                // Variations follow the parent's status (see JsonSource).
                'status'         => $importStatus,
            ];
        }
        $woo['variations']     = $variations;
        $woo['manage_stock']   = false;
        $woo['stock_quantity'] = $totalQty;
        $woo['stock_status']   = $totalQty > 0 ? 'instock' : 'outofstock';
        return $woo;
    }

    /**
     * SF subset filter: keep items whose `_sf_category` (post-normalize)
     * matches the operator's filter. Used by the multi-job-per-subset
     * pattern (e.g. one job per category subset, each with its own
     * markup pipeline override).
     *
     * @param FeedItem[] $items
     * @param mixed      $rawFilter
     * @return FeedItem[]
     */
    private static function applySfCategoryFilter(array $items, $rawFilter): array
    {
        $filter = self::normalizeCategoryFilter($rawFilter);
        if ($filter === []) return $items;
        $out = [];
        foreach ($items as $item) {
            $cat = strtolower(trim((string) ($item->data['_sf_category'] ?? '')));
            $sub = strtolower(trim((string) ($item->data['_sf_subcategory'] ?? '')));
            $matched = false;
            foreach ($filter as $f) {
                if ($cat !== '' && ($cat === $f || str_contains($cat, $f))) { $matched = true; break; }
                if ($sub !== '' && ($sub === $f || str_contains($sub, $f))) { $matched = true; break; }
            }
            if ($matched) $out[] = $item;
        }
        return $out;
    }

    /**
     * Resolve the effective price multiplier from the source-config.
     * Supports two operator-facing modes:
     *
     *   - 'multiplier' (default) — value is the literal factor. 3.5
     *                              means cost × 3.5. Decimals + commas
     *                              accepted (3.5 / 3,5 / 2.0 / 1).
     *   - 'percent'              — value is a markup percent applied
     *                              ABOVE the cost. 250 → +250% → cost × 3.5.
     *                              0 means no markup.
     *
     * Backward compat: if sf_markup_value is empty but the legacy
     * sf_markup_multiplier (int × 10) is present, fall through to it
     * so existing source-configs keep working without manual edit.
     */
    private static function resolveSfMarkup(array $cfg): float
    {
        $rawValue = trim((string) ($cfg['sf_markup_value'] ?? ''));
        if ($rawValue === '') {
            // Legacy field: int × 10 (e.g. 35 → 3.5).
            $legacy = (int) ($cfg['sf_markup_multiplier'] ?? 35);
            return max(0.01, $legacy / 10.0);
        }
        // Accept European decimal separator (3,5 → 3.5).
        $value = (float) str_replace(',', '.', $rawValue);
        $mode  = (string) ($cfg['sf_markup_mode'] ?? 'multiplier');
        if ($mode === 'percent') {
            // 250 → cost × 3.5. Negative percents are clamped to a
            // floor so we never invert the sign of a price.
            return max(0.01, 1.0 + ($value / 100.0));
        }
        return max(0.01, $value);
    }

    /**
     * Build a human-readable warning when the parsed CSV looks
     * suspicious. Most common failure: every row has exactly 1
     * column → delimiter mismatch. Returns '' when shape looks fine.
     */
    private static function diagnoseCsvShape(array $rows, string $delimiter, int $bodyBytes): string
    {
        if (empty($rows)) return '';
        $firstRow = $rows[0];
        $cols     = is_array($firstRow) ? count($firstRow) : 0;

        // Heuristic: if the first row is exactly 1 cell AND the body
        // contains a different common separator, the delimiter is
        // probably wrong.
        if ($cols === 1 && $bodyBytes > 0) {
            $cell = is_array($firstRow) ? (string) ($firstRow[0] ?? '') : '';
            $candidates = [];
            foreach ([ ';' => 'punto e virgola (;)', ',' => 'virgola (,)', "\t" => 'tab', '|' => 'pipe (|)' ] as $char => $label) {
                if ($char === $delimiter) continue;
                if (substr_count($cell, $char) >= 3) $candidates[] = $label;
            }
            $hint = $candidates
                ? ' La prima riga sembra contenere ' . implode(' / ', $candidates) . ' — prova quel delimitatore.'
                : '';
            return 'Le righe sembrano composte da una sola colonna: il delimitatore "' . self::renderDelimiter($delimiter) . '" probabilmente non combacia con il file.' . $hint;
        }
        return '';
    }

    /**
     * SF-specific shape check: how many PRODUCT vs MODEL records are
     * in the body. Mismatch warnings give the operator a clear path
     * back to the source.
     */
    private static function diagnoseSfRows(array $assocRows, ?array $header): string
    {
        if (empty($assocRows)) return '';
        if ($header !== null && ! in_array('RECORD_TYPE', array_map('strval', $header), true)) {
            return 'Header CSV non contiene la colonna RECORD_TYPE — il flavor StockFirmati richiede questa colonna.';
        }
        $product = 0; $model = 0; $other = 0;
        foreach ($assocRows as $r) {
            $t = strtoupper(trim((string) ($r['RECORD_TYPE'] ?? '')));
            if ($t === 'PRODUCT') $product++;
            elseif ($t === 'MODEL') $model++;
            else                   $other++;
        }
        if ($product === 0) {
            return 'Nessuna riga PRODUCT trovata (ne servono per definire i prodotti master). Trovate ' . $model . ' MODEL e ' . $other . ' altre.';
        }
        return '';
    }

    private static function renderDelimiter(string $d): string
    {
        return match ($d) {
            ','  => ',',
            ';'  => ';',
            "\t" => '\\t',
            '|'  => '|',
            default => $d,
        };
    }

    private static function sfClean(string $v): string
    {
        // Strip control chars + collapse whitespace (matches gh_sf_clean).
        $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? $v;
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        return trim($v);
    }

    private static function sfSlug(string $v): string
    {
        if (function_exists('sanitize_title')) return \sanitize_title($v);
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $v) ?? $v);
    }

    /**
     * @param mixed $r
     */
    private static function interpretBridgeResponse($r): MaterializeResult
    {
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
}
