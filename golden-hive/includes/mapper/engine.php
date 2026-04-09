<?php
/**
 * Mapper Engine — core logic for field mapping + transformations.
 *
 * Extracts field paths from a JSON sample, applies transform chains,
 * and maps source data to WooCommerce product structure.
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce target field definitions.
 *
 * @return array[] { key => { label, group, type, description } }
 */
function gh_mapper_get_target_fields(): array {

    return [
        // Generale
        'name'              => [ 'label' => 'Nome',              'group' => 'general',  'type' => 'string',  'desc' => 'Nome prodotto' ],
        'slug'              => [ 'label' => 'Slug',              'group' => 'general',  'type' => 'string',  'desc' => 'URL slug' ],
        'sku'               => [ 'label' => 'SKU',               'group' => 'general',  'type' => 'string',  'desc' => 'Codice univoco' ],
        'type'              => [ 'label' => 'Tipo',              'group' => 'general',  'type' => 'select',  'desc' => 'simple / variable', 'options' => [ 'simple', 'variable' ] ],
        'status'            => [ 'label' => 'Stato',             'group' => 'general',  'type' => 'select',  'desc' => 'publish / draft / private', 'options' => [ 'publish', 'draft', 'private' ] ],

        // Testo
        'description'       => [ 'label' => 'Descrizione',       'group' => 'text',     'type' => 'string',  'desc' => 'Descrizione lunga (HTML)' ],
        'short_description' => [ 'label' => 'Desc. breve',       'group' => 'text',     'type' => 'string',  'desc' => 'Descrizione breve (HTML)' ],

        // Prezzo
        'regular_price'     => [ 'label' => 'Prezzo',            'group' => 'price',    'type' => 'number',  'desc' => 'Prezzo regolare' ],
        'sale_price'        => [ 'label' => 'Prezzo saldo',      'group' => 'price',    'type' => 'number',  'desc' => 'Prezzo scontato' ],

        // Stock
        'manage_stock'      => [ 'label' => 'Gestisci stock',    'group' => 'stock',    'type' => 'boolean', 'desc' => 'Abilita gestione magazzino' ],
        'stock_quantity'    => [ 'label' => 'Quantit\u00e0',     'group' => 'stock',    'type' => 'number',  'desc' => 'Quantit\u00e0 in magazzino' ],
        'stock_status'      => [ 'label' => 'Stato stock',       'group' => 'stock',    'type' => 'select',  'desc' => 'instock / outofstock / onbackorder', 'options' => [ 'instock', 'outofstock', 'onbackorder' ] ],
        'weight'            => [ 'label' => 'Peso',              'group' => 'stock',    'type' => 'number',  'desc' => 'Peso in kg' ],

        // Taxonomy
        'category_ids'      => [ 'label' => 'Categorie',         'group' => 'taxonomy', 'type' => 'array',   'desc' => 'ID categorie (array)' ],
        'tag_ids'           => [ 'label' => 'Tag',               'group' => 'taxonomy', 'type' => 'array',   'desc' => 'ID tag (array)' ],

        // SEO (Rank Math)
        'meta_title'        => [ 'label' => 'Meta Title',        'group' => 'seo',      'type' => 'string',  'desc' => 'Rank Math title' ],
        'meta_description'  => [ 'label' => 'Meta Description',  'group' => 'seo',      'type' => 'string',  'desc' => 'Rank Math description' ],
        'focus_keyword'     => [ 'label' => 'Focus Keyword',     'group' => 'seo',      'type' => 'string',  'desc' => 'Rank Math keyword' ],
    ];
}

/**
 * Available transform types.
 *
 * @return array[] { key => { label, param_label, param_type, description } }
 */
function gh_mapper_get_transform_types(): array {

    return [
        'static'    => [ 'label' => 'Valore fisso',      'param_label' => 'Valore',     'param_type' => 'string', 'desc' => 'Ignora sorgente, usa valore fisso' ],
        'default'   => [ 'label' => 'Default',            'param_label' => 'Default',    'param_type' => 'string', 'desc' => 'Usa questo se sorgente vuota/null' ],
        'prefix'    => [ 'label' => 'Prefisso',           'param_label' => 'Testo',      'param_type' => 'string', 'desc' => 'Aggiungi testo all\'inizio' ],
        'suffix'    => [ 'label' => 'Suffisso',           'param_label' => 'Testo',      'param_type' => 'string', 'desc' => 'Aggiungi testo alla fine' ],
        'replace'   => [ 'label' => 'Sostituisci',        'param_label' => 'Cerca|Sost', 'param_type' => 'string', 'desc' => 'Cerca e sostituisci (cerca|sostituzione)' ],
        'template'  => [ 'label' => 'Template',           'param_label' => 'Template',   'param_type' => 'string', 'desc' => 'Template con {value} come placeholder' ],
        'lowercase' => [ 'label' => 'Minuscolo',          'param_label' => '',           'param_type' => 'none',   'desc' => 'Converti in minuscolo' ],
        'uppercase' => [ 'label' => 'Maiuscolo',          'param_label' => '',           'param_type' => 'none',   'desc' => 'Converti in maiuscolo' ],
        'trim'      => [ 'label' => 'Trim',               'param_label' => '',           'param_type' => 'none',   'desc' => 'Rimuovi spazi iniziali e finali' ],
        'multiply'  => [ 'label' => 'Moltiplica',         'param_label' => 'Fattore',    'param_type' => 'number', 'desc' => 'Moltiplica valore numerico' ],
        'add'       => [ 'label' => 'Aggiungi',           'param_label' => 'Importo',    'param_type' => 'number', 'desc' => 'Somma/sottrai valore numerico' ],
        'round'     => [ 'label' => 'Arrotonda',          'param_label' => 'Decimali',   'param_type' => 'number', 'desc' => 'Arrotonda a N decimali' ],
        'split'     => [ 'label' => 'Separa',             'param_label' => 'Separatore', 'param_type' => 'string', 'desc' => 'Separa stringa in array' ],
        'join'      => [ 'label' => 'Unisci',             'param_label' => 'Separatore', 'param_type' => 'string', 'desc' => 'Unisci array in stringa' ],
        'lookup'    => [ 'label' => 'Lookup',             'param_label' => 'Mappa JSON', 'param_type' => 'string', 'desc' => 'Mappa valori {"vecchio":"nuovo",...}' ],
    ];
}

/**
 * Extracts all dot-notation field paths from a JSON structure.
 *
 * @param mixed  $data   Decoded JSON data.
 * @param string $prefix Current path prefix.
 * @param int    $depth  Max recursion depth.
 * @return array [ { path, type, sample } ]
 */
function gh_mapper_extract_paths( mixed $data, string $prefix = '', int $depth = 8 ): array {

    $paths = [];

    if ( $depth <= 0 || ( ! is_array( $data ) && ! is_object( $data ) ) ) {
        return $paths;
    }

    $items = is_object( $data ) ? get_object_vars( $data ) : $data;

    // If numeric array, inspect first element as representative
    if ( array_is_list( $items ) ) {
        if ( ! empty( $items ) ) {
            $sample_path = $prefix ? $prefix . '[]' : '[]';
            $paths[] = [
                'path'   => $sample_path,
                'type'   => 'array',
                'sample' => count( $items ) . ' items',
            ];
            // Recurse into first element
            $child_paths = gh_mapper_extract_paths( $items[0], $sample_path, $depth - 1 );
            $paths = array_merge( $paths, $child_paths );
        }
        return $paths;
    }

    foreach ( $items as $key => $value ) {
        $path = $prefix ? $prefix . '.' . $key : $key;

        if ( is_array( $value ) && ! array_is_list( $value ) ) {
            // Nested object
            $child_paths = gh_mapper_extract_paths( $value, $path, $depth - 1 );
            $paths = array_merge( $paths, $child_paths );
        } elseif ( is_array( $value ) && array_is_list( $value ) ) {
            $paths[] = [
                'path'   => $path,
                'type'   => 'array',
                'sample' => count( $value ) . ' items',
            ];
            // Recurse into first item if it's an object/array
            if ( ! empty( $value ) && ( is_array( $value[0] ) || is_object( $value[0] ) ) ) {
                $child_paths = gh_mapper_extract_paths( $value[0], $path . '[]', $depth - 1 );
                $paths = array_merge( $paths, $child_paths );
            }
        } else {
            $type = match ( true ) {
                is_null( $value )   => 'null',
                is_bool( $value )   => 'boolean',
                is_int( $value )    => 'integer',
                is_float( $value )  => 'number',
                is_string( $value ) => 'string',
                default             => 'unknown',
            };

            $sample = $value;
            if ( is_string( $value ) && mb_strlen( $value ) > 80 ) {
                $sample = mb_substr( $value, 0, 80 ) . '...';
            }

            $paths[] = [
                'path'   => $path,
                'type'   => $type,
                'sample' => $sample,
            ];
        }
    }

    return $paths;
}

/**
 * Resolves a dot-notation path against a data structure.
 *
 * Supports: "data.name", "items[].sku", "nested.deep.field"
 *
 * @param mixed  $data Source data (array/object).
 * @param string $path Dot-notation path.
 * @return mixed The resolved value, or null if not found.
 */
function gh_mapper_resolve_path( mixed $data, string $path ): mixed {

    $segments = preg_split( '/\./', $path );
    $current  = $data;

    foreach ( $segments as $seg ) {
        if ( $current === null ) return null;

        // Handle array notation: "items[]"
        $is_array_access = str_ends_with( $seg, '[]' );
        $key = $is_array_access ? rtrim( $seg, '[]' ) : $seg;

        if ( $key !== '' ) {
            if ( is_array( $current ) && array_key_exists( $key, $current ) ) {
                $current = $current[ $key ];
            } elseif ( is_object( $current ) && property_exists( $current, $key ) ) {
                $current = $current->$key;
            } else {
                return null;
            }
        }

        // If we hit [] and current is an array, stay on it (caller handles iteration)
        if ( $is_array_access && $key === '' ) {
            // bare [] — current should already be array
        }
    }

    return $current;
}

/**
 * Applies a chain of transforms to a value.
 *
 * @param mixed   $value      The source value.
 * @param array[] $transforms [ { type, value } ]
 * @return mixed Transformed value.
 */
function gh_mapper_apply_transforms( mixed $value, array $transforms ): mixed {

    foreach ( $transforms as $t ) {
        $type  = $t['type'] ?? '';
        $param = $t['value'] ?? '';

        $value = match ( $type ) {
            'static'    => $param,
            'default'   => ( $value === null || $value === '' ) ? $param : $value,
            'prefix'    => $param . (string) $value,
            'suffix'    => (string) $value . $param,
            'replace'   => gh_mapper_transform_replace( $value, $param ),
            'template'  => str_replace( '{value}', (string) $value, $param ),
            'lowercase' => mb_strtolower( (string) $value ),
            'uppercase' => mb_strtoupper( (string) $value ),
            'trim'      => trim( (string) $value ),
            'multiply'  => round( floatval( $value ) * floatval( $param ), 4 ),
            'add'       => round( floatval( $value ) + floatval( $param ), 4 ),
            'round'     => round( floatval( $value ), max( 0, intval( $param ) ) ),
            'split'     => $param !== '' ? explode( $param, (string) $value ) : [ (string) $value ],
            'join'      => is_array( $value ) ? implode( $param, $value ) : (string) $value,
            'lookup'    => gh_mapper_transform_lookup( $value, $param ),
            default     => $value,
        };
    }

    return $value;
}

/**
 * Search-and-replace transform helper.
 */
function gh_mapper_transform_replace( mixed $value, string $param ): string {
    $parts = explode( '|', $param, 2 );
    $search  = $parts[0] ?? '';
    $replace = $parts[1] ?? '';
    return str_replace( $search, $replace, (string) $value );
}

/**
 * Lookup transform helper — maps values via JSON map.
 */
function gh_mapper_transform_lookup( mixed $value, string $param ): mixed {
    $map = json_decode( $param, true );
    if ( ! is_array( $map ) ) return $value;
    $key = (string) $value;
    return $map[ $key ] ?? $value;
}

/**
 * Applies a full mapping rule to a single source item.
 *
 * @param array $source_item One source data item (decoded JSON).
 * @param array $mappings    [ { source, target, transforms[] } ]
 * @return array WooCommerce-ready product data array.
 */
function gh_mapper_apply_rule( array $source_item, array $mappings ): array {

    $product = [];

    foreach ( $mappings as $m ) {
        $source_path = $m['source'] ?? '';
        $target      = $m['target'] ?? '';
        $transforms  = $m['transforms'] ?? [];

        if ( empty( $target ) ) continue;

        // Get source value (null if source is empty = static mapping)
        $value = $source_path !== '' ? gh_mapper_resolve_path( $source_item, $source_path ) : null;

        // Apply transforms
        $value = gh_mapper_apply_transforms( $value, $transforms );

        // Cast to appropriate type based on target field
        $target_fields = gh_mapper_get_target_fields();
        if ( isset( $target_fields[ $target ] ) ) {
            $field_type = $target_fields[ $target ]['type'];
            $value = match ( $field_type ) {
                'number'  => is_numeric( $value ) ? floatval( $value ) : $value,
                'boolean' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ),
                'array'   => is_array( $value ) ? $value : [ $value ],
                default   => $value,
            };
        }

        $product[ $target ] = $value;
    }

    return $product;
}

/**
 * Applies a mapping rule to multiple source items, returning an array of product data.
 *
 * @param array  $source_items Array of source data items.
 * @param array  $mappings     [ { source, target, transforms[] } ]
 * @param string $items_path   Dot-notation path to the items array in the source (e.g., "products", "data.items").
 * @return array[] Array of WooCommerce product data arrays.
 */
function gh_mapper_apply_rule_bulk( array $source_data, array $mappings, string $items_path = '' ): array {

    // Resolve the items array from source data
    if ( $items_path !== '' ) {
        $items = gh_mapper_resolve_path( $source_data, $items_path );
    } else {
        // If no items_path, treat source_data as the items array
        $items = $source_data;
    }

    if ( ! is_array( $items ) || empty( $items ) ) {
        return [];
    }

    // If it's an associative array (single item), wrap it
    if ( ! array_is_list( $items ) ) {
        $items = [ $items ];
    }

    $results = [];
    foreach ( $items as $item ) {
        $results[] = gh_mapper_apply_rule( is_array( $item ) ? $item : (array) $item, $mappings );
    }

    return $results;
}
