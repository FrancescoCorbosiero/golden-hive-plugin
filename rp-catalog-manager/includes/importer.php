<?php
/**
 * Importer — valida, previewa e applica modifiche da un JSON roundtrip.
 * Questo file contiene logica di SCRITTURA su WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

/** Campi scrivibili su un prodotto padre. */
const RP_CM_WRITABLE_PRODUCT_FIELDS = [
    'name', 'slug', 'sku', 'status',
    'description', 'short_description',
    'regular_price', 'sale_price',
    'manage_stock', 'stock_quantity', 'stock_status',
    'weight',
];

/** Campi scrivibili su una variante. */
const RP_CM_WRITABLE_VARIATION_FIELDS = [
    'sku', 'status',
    'regular_price', 'sale_price',
    'manage_stock', 'stock_quantity', 'stock_status',
    'weight',
];

// ── Validation ──────────────────────────────────────────────

/**
 * Valida la struttura di un JSON roundtrip prima dell'import.
 *
 * @param array $data JSON decodificato.
 * @return true|WP_Error
 */
function rp_cm_validate_import_json( array $data ): true|WP_Error {

    if ( ( $data['format'] ?? '' ) !== 'rp_cm_roundtrip' ) {
        return new WP_Error( 'invalid_format', 'Formato non valido. Atteso: rp_cm_roundtrip.' );
    }
    if ( ( $data['version'] ?? 0 ) !== 1 ) {
        return new WP_Error( 'invalid_version', 'Versione non supportata. Attesa: 1.' );
    }
    if ( empty( $data['products'] ) || ! is_array( $data['products'] ) ) {
        return new WP_Error( 'no_products', 'Array "products" mancante o vuoto.' );
    }

    foreach ( $data['products'] as $i => $p ) {
        if ( empty( $p['id'] ) && empty( $p['sku'] ) ) {
            return new WP_Error(
                'no_identifier',
                "Prodotto all'indice {$i}: serve almeno 'id' o 'sku' per il matching."
            );
        }
    }

    return true;
}

// ── Matching ────────────────────────────────────────────────

/**
 * Cerca un prodotto WooCommerce esistente dato id e/o sku.
 *
 * @param array $product_data Dati del prodotto dal JSON.
 * @return int|null ID del prodotto WC se trovato, null altrimenti.
 */
function rp_cm_match_product( array $product_data ): ?int {

    // 1. Match per ID
    if ( ! empty( $product_data['id'] ) ) {
        $product = wc_get_product( (int) $product_data['id'] );
        if ( $product && ! $product->is_type( 'variation' ) ) {
            return $product->get_id();
        }
    }

    // 2. Match per SKU
    if ( ! empty( $product_data['sku'] ) ) {
        $id = wc_get_product_id_by_sku( $product_data['sku'] );
        if ( $id ) {
            $product = wc_get_product( $id );
            if ( $product && ! $product->is_type( 'variation' ) ) {
                return $product->get_id();
            }
        }
    }

    return null;
}

/**
 * Cerca una variante WooCommerce esistente dato id e/o sku.
 *
 * @param array $variation_data Dati della variante dal JSON.
 * @param int   $parent_id     ID del prodotto padre.
 * @return int|null ID della variante se trovata.
 */
function rp_cm_match_variation( array $variation_data, int $parent_id ): ?int {

    // 1. Match per ID (verifica che appartenga al parent)
    if ( ! empty( $variation_data['id'] ) ) {
        $v = wc_get_product( (int) $variation_data['id'] );
        if ( $v && $v->is_type( 'variation' ) && $v->get_parent_id() === $parent_id ) {
            return $v->get_id();
        }
    }

    // 2. Match per SKU
    if ( ! empty( $variation_data['sku'] ) ) {
        $id = wc_get_product_id_by_sku( $variation_data['sku'] );
        if ( $id ) {
            $v = wc_get_product( $id );
            if ( $v && $v->is_type( 'variation' ) && $v->get_parent_id() === $parent_id ) {
                return $v->get_id();
            }
        }
    }

    return null;
}

// ── Preview (dry-run) ───────────────────────────────────────

/**
 * Esegue un dry-run dell'import: mostra cosa cambierebbe senza scrivere.
 *
 * @param array  $data JSON roundtrip decodificato.
 * @param string $mode 'update_only' | 'create_if_missing'
 * @return array Preview con matched, skipped, would_create, summary.
 */
function rp_cm_import_preview( array $data, string $mode = 'update_only' ): array {

    $results = [];

    foreach ( $data['products'] as $entry ) {
        $matched_id = rp_cm_match_product( $entry );

        if ( $matched_id ) {
            $product = wc_get_product( $matched_id );
            $changes = rp_cm_diff_product( $product, $entry );

            // Preview varianti
            $var_results = [];
            foreach ( $entry['variations'] ?? [] as $var_entry ) {
                $var_id = rp_cm_match_variation( $var_entry, $matched_id );
                if ( $var_id ) {
                    $variation    = wc_get_product( $var_id );
                    $var_changes  = rp_cm_diff_variation( $variation, $var_entry );
                    $var_results[] = [
                        'id'      => $var_id,
                        'sku'     => $var_entry['sku'] ?? $variation->get_sku(),
                        'status'  => 'matched',
                        'changes' => $var_changes,
                    ];
                } else {
                    $var_results[] = [
                        'id'      => $var_entry['id'] ?? null,
                        'sku'     => $var_entry['sku'] ?? null,
                        'status'  => 'skipped',
                        'reason'  => 'Variante non trovata',
                        'changes' => [],
                    ];
                }
            }

            $results[] = [
                'id'                => $matched_id,
                'sku'               => $entry['sku'] ?? $product->get_sku(),
                'name'              => $entry['name'] ?? $product->get_name(),
                'status'            => 'matched',
                'changes'           => $changes,
                'variation_results' => $var_results,
            ];
        } elseif ( $mode === 'create_if_missing' && ( $entry['type'] ?? 'simple' ) === 'simple' ) {
            $results[] = [
                'id'                => null,
                'sku'               => $entry['sku'] ?? null,
                'name'              => $entry['name'] ?? 'Senza nome',
                'status'            => 'would_create',
                'changes'           => [],
                'variation_results' => [],
            ];
        } else {
            $reason = $mode === 'update_only'
                ? 'Nessun prodotto corrispondente'
                : 'Creazione prodotti variabili non supportata';
            $results[] = [
                'id'                => $entry['id'] ?? null,
                'sku'               => $entry['sku'] ?? null,
                'name'              => $entry['name'] ?? '?',
                'status'            => 'skipped',
                'reason'            => $reason,
                'changes'           => [],
                'variation_results' => [],
            ];
        }
    }

    // Calcola summary
    $matched      = count( array_filter( $results, fn( $r ) => $r['status'] === 'matched' ) );
    $skipped      = count( array_filter( $results, fn( $r ) => $r['status'] === 'skipped' ) );
    $would_create = count( array_filter( $results, fn( $r ) => $r['status'] === 'would_create' ) );
    $with_changes = count( array_filter( $results, fn( $r ) => $r['status'] === 'matched' && ! empty( $r['changes'] ) ) );

    $var_total   = 0;
    $var_changes = 0;
    $var_skipped = 0;
    foreach ( $results as $r ) {
        foreach ( $r['variation_results'] as $vr ) {
            $var_total++;
            if ( $vr['status'] === 'skipped' ) $var_skipped++;
            elseif ( ! empty( $vr['changes'] ) ) $var_changes++;
        }
    }

    return [
        'summary' => [
            'total_in_file'         => count( $data['products'] ),
            'matched'               => $matched,
            'with_changes'          => $with_changes,
            'skipped'               => $skipped,
            'would_create'          => $would_create,
            'variations_total'      => $var_total,
            'variations_with_changes' => $var_changes,
            'variations_skipped'    => $var_skipped,
        ],
        'details' => $results,
    ];
}

// ── Apply (writes to DB) ────────────────────────────────────

/**
 * Applica le modifiche dal JSON roundtrip a WooCommerce.
 *
 * @param array  $data JSON roundtrip decodificato.
 * @param string $mode 'update_only' | 'create_if_missing'
 * @return array Risultato con summary e details.
 */
function rp_cm_import_apply( array $data, string $mode = 'update_only' ): array {

    $results = [];

    foreach ( $data['products'] as $entry ) {
        $matched_id = rp_cm_match_product( $entry );

        if ( $matched_id ) {
            $result = rp_cm_apply_product( $matched_id, $entry );
            $results[] = $result;
        } elseif ( $mode === 'create_if_missing' && ( $entry['type'] ?? 'simple' ) === 'simple' ) {
            $result = rp_cm_create_product_from_entry( $entry );
            $results[] = $result;
        } else {
            $results[] = [
                'id'                => $entry['id'] ?? null,
                'sku'               => $entry['sku'] ?? null,
                'name'              => $entry['name'] ?? '?',
                'status'            => 'skipped',
                'reason'            => 'Nessun prodotto corrispondente',
                'changes'           => [],
                'variation_results' => [],
            ];
        }
    }

    // Summary
    $updated = count( array_filter( $results, fn( $r ) => $r['status'] === 'updated' ) );
    $created = count( array_filter( $results, fn( $r ) => $r['status'] === 'created' ) );
    $skipped = count( array_filter( $results, fn( $r ) => $r['status'] === 'skipped' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['status'] === 'error' ) );

    $var_updated = 0;
    $var_skipped = 0;
    $var_errors  = 0;
    foreach ( $results as $r ) {
        foreach ( $r['variation_results'] ?? [] as $vr ) {
            match ( $vr['status'] ) {
                'updated' => $var_updated++,
                'skipped' => $var_skipped++,
                'error'   => $var_errors++,
                default   => null,
            };
        }
    }

    return [
        'summary' => [
            'total_in_file'       => count( $data['products'] ),
            'updated'             => $updated,
            'created'             => $created,
            'skipped'             => $skipped,
            'errors'              => $errors,
            'variations_updated'  => $var_updated,
            'variations_skipped'  => $var_skipped,
            'variations_errors'   => $var_errors,
        ],
        'details' => $results,
    ];
}

// ── Apply helpers ───────────────────────────────────────────

/**
 * Applica le modifiche a un prodotto esistente + sue varianti.
 *
 * @param int   $product_id ID del prodotto WC.
 * @param array $entry      Dati dal JSON.
 * @return array Risultato per questo prodotto.
 */
function rp_cm_apply_product( int $product_id, array $entry ): array {

    $product = wc_get_product( $product_id );

    try {
        $changes = rp_cm_apply_product_fields( $product, $entry );
        $product->save();

        // Taxonomy: category_ids
        if ( array_key_exists( 'category_ids', $entry ) ) {
            wp_set_object_terms( $product_id, array_map( 'intval', $entry['category_ids'] ), 'product_cat' );
            $changes[] = 'category_ids';
        }

        // Taxonomy: tag_ids
        if ( array_key_exists( 'tag_ids', $entry ) ) {
            wp_set_object_terms( $product_id, array_map( 'intval', $entry['tag_ids'] ), 'product_tag' );
            $changes[] = 'tag_ids';
        }

        // Rank Math meta
        foreach ( [ 'meta_title' => 'rank_math_title', 'meta_description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' ] as $json_key => $meta_key ) {
            if ( array_key_exists( $json_key, $entry ) ) {
                update_post_meta( $product_id, $meta_key, sanitize_text_field( $entry[ $json_key ] ?? '' ) );
                $changes[] = $json_key;
            }
        }

        // Varianti
        $var_results = [];
        $needs_sync  = false;
        foreach ( $entry['variations'] ?? [] as $var_entry ) {
            $var_id = rp_cm_match_variation( $var_entry, $product_id );
            if ( ! $var_id ) {
                $var_results[] = [
                    'id'     => $var_entry['id'] ?? null,
                    'sku'    => $var_entry['sku'] ?? null,
                    'status' => 'skipped',
                    'reason' => 'Variante non trovata',
                    'changes' => [],
                ];
                continue;
            }

            try {
                $variation   = wc_get_product( $var_id );
                $var_changes = rp_cm_apply_variation_fields( $variation, $var_entry );
                $variation->save();
                $needs_sync = true;

                $var_results[] = [
                    'id'      => $var_id,
                    'sku'     => $variation->get_sku(),
                    'status'  => 'updated',
                    'changes' => $var_changes,
                ];
            } catch ( \Exception $e ) {
                $var_results[] = [
                    'id'      => $var_id,
                    'sku'     => $var_entry['sku'] ?? null,
                    'status'  => 'error',
                    'reason'  => $e->getMessage(),
                    'changes' => [],
                ];
            }
        }

        // Sync parent una sola volta dopo tutte le varianti
        if ( $needs_sync && $product->is_type( 'variable' ) ) {
            WC_Product_Variable::sync( $product_id );
        }

        return [
            'id'                => $product_id,
            'sku'               => $product->get_sku(),
            'name'              => $product->get_name(),
            'status'            => 'updated',
            'changes'           => array_unique( $changes ),
            'variation_results' => $var_results,
        ];

    } catch ( \Exception $e ) {
        return [
            'id'                => $product_id,
            'sku'               => $entry['sku'] ?? null,
            'name'              => $entry['name'] ?? '?',
            'status'            => 'error',
            'reason'            => $e->getMessage(),
            'changes'           => [],
            'variation_results' => [],
        ];
    }
}

/**
 * Crea un nuovo prodotto simple da un'entry roundtrip.
 *
 * @param array $entry Dati dal JSON.
 * @return array Risultato.
 */
function rp_cm_create_product_from_entry( array $entry ): array {

    try {
        $product = new WC_Product_Simple();
        rp_cm_apply_product_fields( $product, $entry );
        $product_id = $product->save();

        if ( ! empty( $entry['category_ids'] ) ) {
            wp_set_object_terms( $product_id, array_map( 'intval', $entry['category_ids'] ), 'product_cat' );
        }
        if ( ! empty( $entry['tag_ids'] ) ) {
            wp_set_object_terms( $product_id, array_map( 'intval', $entry['tag_ids'] ), 'product_tag' );
        }
        foreach ( [ 'meta_title' => 'rank_math_title', 'meta_description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' ] as $json_key => $meta_key ) {
            if ( ! empty( $entry[ $json_key ] ) ) {
                update_post_meta( $product_id, $meta_key, sanitize_text_field( $entry[ $json_key ] ) );
            }
        }

        return [
            'id'                => $product_id,
            'sku'               => $entry['sku'] ?? null,
            'name'              => $entry['name'] ?? '',
            'status'            => 'created',
            'changes'           => [],
            'variation_results' => [],
        ];

    } catch ( \Exception $e ) {
        return [
            'id'                => null,
            'sku'               => $entry['sku'] ?? null,
            'name'              => $entry['name'] ?? '?',
            'status'            => 'error',
            'reason'            => $e->getMessage(),
            'changes'           => [],
            'variation_results' => [],
        ];
    }
}

/**
 * Imposta i campi scrivibili su un oggetto WC_Product.
 *
 * @param WC_Product $product
 * @param array      $data Dati dal JSON.
 * @return array Lista dei nomi dei campi modificati.
 */
function rp_cm_apply_product_fields( WC_Product $product, array $data ): array {

    $changes = [];

    $setters = [
        'name'              => 'set_name',
        'slug'              => 'set_slug',
        'sku'               => 'set_sku',
        'status'            => 'set_status',
        'description'       => 'set_description',
        'short_description' => 'set_short_description',
        'regular_price'     => 'set_regular_price',
        'sale_price'        => 'set_sale_price',
        'weight'            => 'set_weight',
    ];

    foreach ( $setters as $field => $setter ) {
        if ( array_key_exists( $field, $data ) ) {
            $product->$setter( $data[ $field ] );
            $changes[] = $field;
        }
    }

    // Stock richiede logica dedicata
    if ( array_key_exists( 'manage_stock', $data ) ) {
        $product->set_manage_stock( $data['manage_stock'] );
        $changes[] = 'manage_stock';
    }
    if ( array_key_exists( 'stock_quantity', $data ) ) {
        $product->set_stock_quantity( $data['stock_quantity'] );
        $changes[] = 'stock_quantity';
    }
    if ( array_key_exists( 'stock_status', $data ) ) {
        $product->set_stock_status( $data['stock_status'] );
        $changes[] = 'stock_status';
    }

    return $changes;
}

/**
 * Imposta i campi scrivibili su un oggetto WC_Product_Variation.
 *
 * @param WC_Product_Variation $variation
 * @param array                $data Dati dal JSON.
 * @return array Lista dei nomi dei campi modificati.
 */
function rp_cm_apply_variation_fields( WC_Product_Variation $variation, array $data ): array {

    $changes = [];

    $setters = [
        'sku'            => 'set_sku',
        'status'         => 'set_status',
        'regular_price'  => 'set_regular_price',
        'sale_price'     => 'set_sale_price',
        'weight'         => 'set_weight',
    ];

    foreach ( $setters as $field => $setter ) {
        if ( array_key_exists( $field, $data ) ) {
            $variation->$setter( $data[ $field ] );
            $changes[] = $field;
        }
    }

    if ( array_key_exists( 'manage_stock', $data ) ) {
        $variation->set_manage_stock( $data['manage_stock'] );
        $changes[] = 'manage_stock';
    }
    if ( array_key_exists( 'stock_quantity', $data ) ) {
        $variation->set_stock_quantity( $data['stock_quantity'] );
        $changes[] = 'stock_quantity';
    }
    if ( array_key_exists( 'stock_status', $data ) ) {
        $variation->set_stock_status( $data['stock_status'] );
        $changes[] = 'stock_status';
    }

    return $changes;
}

// ── Diff helpers (per preview) ──────────────────────────────

/**
 * Confronta un prodotto WC con un'entry JSON e ritorna le differenze.
 *
 * @param WC_Product $product
 * @param array      $entry
 * @return array [ [ 'field' => '...', 'old' => '...', 'new' => '...' ], ... ]
 */
function rp_cm_diff_product( WC_Product $product, array $entry ): array {

    $changes = [];
    $id      = $product->get_id();

    $getters = [
        'name'              => fn() => $product->get_name(),
        'slug'              => fn() => $product->get_slug(),
        'sku'               => fn() => $product->get_sku(),
        'status'            => fn() => $product->get_status(),
        'description'       => fn() => $product->get_description(),
        'short_description' => fn() => $product->get_short_description(),
        'regular_price'     => fn() => $product->get_regular_price(),
        'sale_price'        => fn() => $product->get_sale_price(),
        'manage_stock'      => fn() => $product->get_manage_stock(),
        'stock_quantity'    => fn() => $product->get_stock_quantity(),
        'stock_status'      => fn() => $product->get_stock_status(),
        'weight'            => fn() => $product->get_weight(),
        'meta_title'        => fn() => get_post_meta( $id, 'rank_math_title', true ),
        'meta_description'  => fn() => get_post_meta( $id, 'rank_math_description', true ),
        'focus_keyword'     => fn() => get_post_meta( $id, 'rank_math_focus_keyword', true ),
    ];

    foreach ( $getters as $field => $getter ) {
        if ( ! array_key_exists( $field, $entry ) ) continue;

        $old = $getter();
        $new = $entry[ $field ];

        // Normalizza per confronto
        if ( rp_cm_values_differ( $old, $new ) ) {
            $changes[] = [
                'field' => $field,
                'old'   => $old,
                'new'   => $new,
            ];
        }
    }

    // Category IDs
    if ( array_key_exists( 'category_ids', $entry ) ) {
        $old = rp_cm_get_product_category_ids( $id );
        $new = array_map( 'intval', $entry['category_ids'] );
        sort( $old );
        sort( $new );
        if ( $old !== $new ) {
            $changes[] = [ 'field' => 'category_ids', 'old' => $old, 'new' => $new ];
        }
    }

    // Tag IDs
    if ( array_key_exists( 'tag_ids', $entry ) ) {
        $old = rp_cm_get_product_tag_ids( $id );
        $new = array_map( 'intval', $entry['tag_ids'] );
        sort( $old );
        sort( $new );
        if ( $old !== $new ) {
            $changes[] = [ 'field' => 'tag_ids', 'old' => $old, 'new' => $new ];
        }
    }

    return $changes;
}

/**
 * Confronta una variante WC con un'entry JSON.
 *
 * @param WC_Product_Variation $variation
 * @param array                $entry
 * @return array
 */
function rp_cm_diff_variation( WC_Product_Variation $variation, array $entry ): array {

    $changes = [];

    $getters = [
        'sku'            => fn() => $variation->get_sku(),
        'status'         => fn() => $variation->get_status(),
        'regular_price'  => fn() => $variation->get_regular_price(),
        'sale_price'     => fn() => $variation->get_sale_price(),
        'manage_stock'   => fn() => $variation->get_manage_stock(),
        'stock_quantity' => fn() => $variation->get_stock_quantity(),
        'stock_status'   => fn() => $variation->get_stock_status(),
        'weight'         => fn() => $variation->get_weight(),
    ];

    foreach ( $getters as $field => $getter ) {
        if ( ! array_key_exists( $field, $entry ) ) continue;

        $old = $getter();
        $new = $entry[ $field ];

        if ( rp_cm_values_differ( $old, $new ) ) {
            $changes[] = [
                'field' => $field,
                'old'   => $old,
                'new'   => $new,
            ];
        }
    }

    return $changes;
}

/**
 * Confronta due valori con normalizzazione di tipo.
 *
 * @param mixed $old
 * @param mixed $new
 * @return bool True se i valori sono diversi.
 */
function rp_cm_values_differ( mixed $old, mixed $new ): bool {

    // Normalizza: stringhe vuote e null sono uguali
    if ( ( $old === '' || $old === null ) && ( $new === '' || $new === null ) ) {
        return false;
    }

    // Normalizza: bool
    if ( is_bool( $old ) || is_bool( $new ) ) {
        return (bool) $old !== (bool) $new;
    }

    // Stringhe: confronto diretto
    return (string) $old !== (string) $new;
}
