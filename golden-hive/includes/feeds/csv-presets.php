<?php
/**
 * CSV Presets — built-in mapping configurations + auto-mapper.
 *
 * Three mapping modes for CSV feeds:
 * 1. "preset"  → use a named built-in config (no manual setup)
 * 2. "auto"    → detect WooCommerce fields from CSV column names
 * 3. "rule"    → use a manually-configured mapper rule (existing flow)
 */

defined( 'ABSPATH' ) || exit;

// ── Built-in Presets ──────────────────────────────────────

/**
 * Returns all built-in CSV mapping presets.
 *
 * Each preset: { id, name, description, mappings[] }
 * mappings follow the same format as mapper rules:
 *   { source: 'csv_column', target: 'woo_field', transforms: [] }
 *
 * @return array[] Keyed by preset ID.
 */
function gh_csv_get_presets(): array {

    return [

        // ── Generic sneaker supplier ────────────────────
        'sneaker_supplier' => [
            'id'          => 'sneaker_supplier',
            'name'        => 'Fornitore Sneakers (generico)',
            'description' => 'CSV con colonne: brand, model/name, sku/code, price, sale_price, quantity, size, barcode, image_url. Crea prodotti simple con stock e prezzi.',
            'mappings'    => [
                [ 'source' => 'name',          'target' => 'name',           'transforms' => [] ],
                [ 'source' => 'sku',           'target' => 'sku',            'transforms' => [ [ 'type' => 'trim', 'value' => '' ] ] ],
                [ 'source' => 'price',         'target' => 'regular_price',  'transforms' => [ [ 'type' => 'round', 'value' => '2' ] ] ],
                [ 'source' => 'sale_price',    'target' => 'sale_price',     'transforms' => [ [ 'type' => 'round', 'value' => '2' ] ] ],
                [ 'source' => 'quantity',      'target' => 'stock_quantity', 'transforms' => [] ],
                [ 'source' => 'description',   'target' => 'description',    'transforms' => [] ],
                [ 'source' => 'weight',        'target' => 'weight',         'transforms' => [] ],
                [ 'source' => '',              'target' => 'manage_stock',   'transforms' => [ [ 'type' => 'static', 'value' => '1' ] ] ],
                [ 'source' => '',              'target' => 'status',         'transforms' => [ [ 'type' => 'static', 'value' => 'publish' ] ] ],
            ],
            'column_aliases' => [
                'name'        => [ 'name', 'product_name', 'title', 'nome', 'prodotto', 'nome_prodotto' ],
                'sku'         => [ 'sku', 'code', 'codice', 'article', 'article_number', 'articolo', 'style_code', 'cod_art' ],
                'price'       => [ 'price', 'retail_price', 'prezzo', 'regular_price', 'prezzo_listino', 'listino' ],
                'sale_price'  => [ 'sale_price', 'offer_price', 'prezzo_scontato', 'prezzo_offerta', 'prezzo_vendita', 'selling_price' ],
                'quantity'    => [ 'quantity', 'stock', 'qty', 'disponibilita', 'disponibilità', 'available', 'giacenza', 'available_quantity' ],
                'description' => [ 'description', 'desc', 'descrizione' ],
                'weight'      => [ 'weight', 'peso' ],
            ],
        ],

        // ── Sneaker supplier with markup ────────────────
        'sneaker_markup' => [
            'id'          => 'sneaker_markup',
            'name'        => 'Fornitore Sneakers (con ricarico 30%)',
            'description' => 'Come "generico" ma applica x1.3 al prezzo per generare regular_price (prezzo barrato). Il prezzo sorgente diventa sale_price.',
            'mappings'    => [
                [ 'source' => 'name',          'target' => 'name',           'transforms' => [] ],
                [ 'source' => 'sku',           'target' => 'sku',            'transforms' => [ [ 'type' => 'trim', 'value' => '' ] ] ],
                [ 'source' => 'price',         'target' => 'sale_price',     'transforms' => [ [ 'type' => 'round', 'value' => '0' ] ] ],
                [ 'source' => 'price',         'target' => 'regular_price',  'transforms' => [ [ 'type' => 'multiply', 'value' => '1.3' ], [ 'type' => 'round', 'value' => '0' ] ] ],
                [ 'source' => 'quantity',      'target' => 'stock_quantity', 'transforms' => [] ],
                [ 'source' => 'description',   'target' => 'description',    'transforms' => [] ],
                [ 'source' => '',              'target' => 'manage_stock',   'transforms' => [ [ 'type' => 'static', 'value' => '1' ] ] ],
                [ 'source' => '',              'target' => 'status',         'transforms' => [ [ 'type' => 'static', 'value' => 'publish' ] ] ],
            ],
            'column_aliases' => [
                'name'        => [ 'name', 'product_name', 'title', 'nome', 'prodotto' ],
                'sku'         => [ 'sku', 'code', 'codice', 'article', 'articolo', 'style_code', 'cod_art' ],
                'price'       => [ 'price', 'offer_price', 'prezzo', 'presented_price', 'prezzo_vendita', 'selling_price', 'costo' ],
                'quantity'    => [ 'quantity', 'stock', 'qty', 'disponibilita', 'disponibilità', 'available', 'giacenza', 'available_quantity' ],
                'description' => [ 'description', 'desc', 'descrizione' ],
            ],
        ],

        // ── Minimal (just name + sku + price) ───────────
        'minimal' => [
            'id'          => 'minimal',
            'name'        => 'Minimale (nome, SKU, prezzo)',
            'description' => 'Mappa solo nome, SKU e prezzo. Per CSV semplici o test.',
            'mappings'    => [
                [ 'source' => 'name',   'target' => 'name',          'transforms' => [] ],
                [ 'source' => 'sku',    'target' => 'sku',           'transforms' => [ [ 'type' => 'trim', 'value' => '' ] ] ],
                [ 'source' => 'price',  'target' => 'regular_price', 'transforms' => [ [ 'type' => 'round', 'value' => '2' ] ] ],
                [ 'source' => '',       'target' => 'status',        'transforms' => [ [ 'type' => 'static', 'value' => 'publish' ] ] ],
            ],
            'column_aliases' => [
                'name'  => [ 'name', 'product_name', 'title', 'nome', 'prodotto' ],
                'sku'   => [ 'sku', 'code', 'codice', 'article', 'articolo' ],
                'price' => [ 'price', 'prezzo', 'regular_price', 'retail_price', 'costo' ],
            ],
        ],

        // ── Full product (all fields) ───────────────────
        'full_product' => [
            'id'          => 'full_product',
            'name'        => 'Prodotto completo',
            'description' => 'Mappa tutti i campi disponibili: nome, SKU, prezzi, stock, descrizione, peso, stato.',
            'mappings'    => [
                [ 'source' => 'name',              'target' => 'name',              'transforms' => [] ],
                [ 'source' => 'sku',               'target' => 'sku',               'transforms' => [ [ 'type' => 'trim', 'value' => '' ] ] ],
                [ 'source' => 'regular_price',     'target' => 'regular_price',     'transforms' => [ [ 'type' => 'round', 'value' => '2' ] ] ],
                [ 'source' => 'sale_price',        'target' => 'sale_price',        'transforms' => [ [ 'type' => 'round', 'value' => '2' ] ] ],
                [ 'source' => 'stock_quantity',    'target' => 'stock_quantity',    'transforms' => [] ],
                [ 'source' => 'description',       'target' => 'description',       'transforms' => [] ],
                [ 'source' => 'short_description', 'target' => 'short_description', 'transforms' => [] ],
                [ 'source' => 'weight',            'target' => 'weight',            'transforms' => [] ],
                [ 'source' => 'status',            'target' => 'status',            'transforms' => [ [ 'type' => 'default', 'value' => 'publish' ] ] ],
                [ 'source' => '',                  'target' => 'manage_stock',      'transforms' => [ [ 'type' => 'static', 'value' => '1' ] ] ],
            ],
            'column_aliases' => [
                'name'              => [ 'name', 'product_name', 'title', 'nome' ],
                'sku'               => [ 'sku', 'code', 'codice', 'article', 'articolo' ],
                'regular_price'     => [ 'regular_price', 'price', 'prezzo', 'listino', 'retail_price' ],
                'sale_price'        => [ 'sale_price', 'offer_price', 'prezzo_scontato', 'prezzo_offerta' ],
                'stock_quantity'    => [ 'stock_quantity', 'quantity', 'stock', 'qty', 'disponibilita', 'giacenza' ],
                'description'       => [ 'description', 'desc', 'descrizione' ],
                'short_description' => [ 'short_description', 'short_desc', 'desc_breve' ],
                'weight'            => [ 'weight', 'peso' ],
                'status'            => [ 'status', 'stato' ],
            ],
        ],
    ];
}

/**
 * Gets a single preset by ID.
 */
function gh_csv_get_preset( string $preset_id ): ?array {
    return gh_csv_get_presets()[ $preset_id ] ?? null;
}

// ── Auto-mapper ───────────────────────────────────────────

/**
 * Known CSV column names → WooCommerce target fields.
 * Lowercase, covers IT and EN naming conventions.
 */
function gh_csv_get_column_map(): array {
    return [
        // Name
        'name'              => 'name',
        'product_name'      => 'name',
        'title'             => 'name',
        'nome'              => 'name',
        'prodotto'          => 'name',
        'nome_prodotto'     => 'name',
        'nome prodotto'     => 'name',

        // SKU
        'sku'               => 'sku',
        'code'              => 'sku',
        'codice'            => 'sku',
        'article'           => 'sku',
        'article_number'    => 'sku',
        'articolo'          => 'sku',
        'style_code'        => 'sku',
        'cod_art'           => 'sku',
        'codice_articolo'   => 'sku',
        'product_code'      => 'sku',
        'ref'               => 'sku',
        'reference'         => 'sku',
        'model_code'        => 'sku',

        // Regular price
        'regular_price'     => 'regular_price',
        'price'             => 'regular_price',
        'prezzo'            => 'regular_price',
        'retail_price'      => 'regular_price',
        'prezzo_listino'    => 'regular_price',
        'listino'           => 'regular_price',
        'msrp'              => 'regular_price',
        'rrp'               => 'regular_price',

        // Sale price
        'sale_price'        => 'sale_price',
        'offer_price'       => 'sale_price',
        'prezzo_scontato'   => 'sale_price',
        'prezzo_offerta'    => 'sale_price',
        'prezzo_vendita'    => 'sale_price',
        'selling_price'     => 'sale_price',
        'presented_price'   => 'sale_price',
        'costo'             => 'sale_price',
        'net_price'         => 'sale_price',

        // Stock
        'stock_quantity'    => 'stock_quantity',
        'quantity'          => 'stock_quantity',
        'stock'             => 'stock_quantity',
        'qty'               => 'stock_quantity',
        'disponibilita'     => 'stock_quantity',
        'disponibilità'     => 'stock_quantity',
        'available'         => 'stock_quantity',
        'giacenza'          => 'stock_quantity',
        'available_quantity' => 'stock_quantity',
        'inventory'         => 'stock_quantity',

        // Description
        'description'       => 'description',
        'desc'              => 'description',
        'descrizione'       => 'description',
        'product_description' => 'description',

        // Short description
        'short_description' => 'short_description',
        'short_desc'        => 'short_description',
        'desc_breve'        => 'short_description',

        // Weight
        'weight'            => 'weight',
        'peso'              => 'weight',

        // Status
        'status'            => 'status',
        'stato'             => 'status',

        // Slug
        'slug'              => 'slug',
    ];
}

/**
 * Auto-maps CSV columns to WooCommerce fields by matching column headers.
 *
 * @param array $columns CSV column headers.
 * @return array { mappings[], matched_columns[], unmatched_columns[] }
 */
function gh_csv_auto_map( array $columns ): array {
    $column_map   = gh_csv_get_column_map();
    $mappings     = [];
    $matched      = [];
    $unmatched    = [];
    $used_targets = [];

    foreach ( $columns as $col ) {
        $key = strtolower( trim( $col ) );
        // Normalize: underscores, remove extra spaces
        $key_normalized = preg_replace( '/[\s\-]+/', '_', $key );

        $target = $column_map[ $key ] ?? $column_map[ $key_normalized ] ?? null;

        if ( $target && ! isset( $used_targets[ $target ] ) ) {
            $transforms = [];

            // Auto-add transforms based on field type
            if ( $target === 'sku' ) {
                $transforms[] = [ 'type' => 'trim', 'value' => '' ];
            }
            if ( in_array( $target, [ 'regular_price', 'sale_price' ], true ) ) {
                $transforms[] = [ 'type' => 'round', 'value' => '2' ];
            }

            $mappings[] = [
                'source'     => $col,  // use original column name (case-sensitive)
                'target'     => $target,
                'transforms' => $transforms,
            ];
            $matched[] = $col;
            $used_targets[ $target ] = true;
        } else {
            $unmatched[] = $col;
        }
    }

    // Always add manage_stock = true if stock_quantity was matched
    if ( isset( $used_targets['stock_quantity'] ) ) {
        $mappings[] = [
            'source'     => '',
            'target'     => 'manage_stock',
            'transforms' => [ [ 'type' => 'static', 'value' => '1' ] ],
        ];
    }

    // Default status = publish if not mapped
    if ( ! isset( $used_targets['status'] ) ) {
        $mappings[] = [
            'source'     => '',
            'target'     => 'status',
            'transforms' => [ [ 'type' => 'static', 'value' => 'publish' ] ],
        ];
    }

    return [
        'mappings'           => $mappings,
        'matched_columns'    => $matched,
        'unmatched_columns'  => $unmatched,
    ];
}

/**
 * Resolves a preset's mappings against actual CSV columns using column_aliases.
 *
 * Takes a preset and the actual CSV column headers, and returns mappings
 * with source fields resolved to real column names.
 *
 * @param array $preset Preset config from gh_csv_get_presets().
 * @param array $columns Actual CSV column headers.
 * @return array Resolved mappings (same format as mapper rules).
 */
function gh_csv_resolve_preset( array $preset, array $columns ): array {
    $aliases      = $preset['column_aliases'] ?? [];
    $mappings     = $preset['mappings'] ?? [];
    $columns_lower = array_map( fn( $c ) => strtolower( trim( $c ) ), $columns );
    // Map lowercase → original for case preservation
    $col_lookup   = array_combine( $columns_lower, $columns );
    $resolved     = [];

    foreach ( $mappings as $m ) {
        $source = $m['source'] ?? '';

        if ( $source === '' ) {
            // Static mapping — no source column needed
            $resolved[] = $m;
            continue;
        }

        // Try to find the actual CSV column for this source
        $actual_col = null;

        // Direct match first
        if ( isset( $col_lookup[ strtolower( $source ) ] ) ) {
            $actual_col = $col_lookup[ strtolower( $source ) ];
        }

        // Then try aliases
        if ( ! $actual_col && isset( $aliases[ $source ] ) ) {
            foreach ( $aliases[ $source ] as $alias ) {
                if ( isset( $col_lookup[ strtolower( $alias ) ] ) ) {
                    $actual_col = $col_lookup[ strtolower( $alias ) ];
                    break;
                }
            }
        }

        if ( $actual_col ) {
            $resolved[] = array_merge( $m, [ 'source' => $actual_col ] );
        }
        // Skip mappings with no matching column (no error, just omit)
    }

    return $resolved;
}
