<?php
/**
 * Smart Taxonomy — regole automatiche per popolare termini di tassonomia.
 *
 * Concetto Shopify "Smart Collections" portato su qualsiasi tassonomia WC:
 * crei un termine (categoria, brand, tag), gli associ delle condizioni
 * (stessi 19 tipi del filter engine di Filtra & Agisci), e il sistema
 * assegna automaticamente i prodotti corrispondenti. Funziona anche per i
 * prodotti futuri: un hook su woocommerce_new_product / _update_product
 * ri-valuta tutte le regole abilitate.
 *
 * Le condizioni vengono valutate da gh_filter_product_ids() (filter/
 * query-engine.php) — stesso motore, stessi tipi, stessa logica a 2 fasi
 * (DB + memoria). Zero duplicazione.
 *
 * Storage: wp_option `gh_smart_taxonomy_rules` (array JSON-safe).
 */

defined( 'ABSPATH' ) || exit;

const GH_SMART_RULES_KEY = 'gh_smart_taxonomy_rules';

// ── CRUD ──────────────────────────────────────────────────────────────────

/**
 * Ritorna tutte le regole smart.
 *
 * @return array Mappa rule_id → rule data.
 */
function gh_smart_get_rules(): array {
    $rules = get_option( GH_SMART_RULES_KEY, [] );
    return is_array( $rules ) ? $rules : [];
}

/**
 * Ritorna una singola regola.
 *
 * @param string $rule_id
 * @return array|null
 */
function gh_smart_get_rule( string $rule_id ): ?array {
    $rules = gh_smart_get_rules();
    return $rules[ $rule_id ] ?? null;
}

/**
 * Ritorna le regole associate a un termine specifico (ce ne puo essere una per termine).
 *
 * @param int    $term_id
 * @param string $taxonomy
 * @return array|null
 */
function gh_smart_get_rule_for_term( int $term_id, string $taxonomy = 'product_cat' ): ?array {
    foreach ( gh_smart_get_rules() as $id => $rule ) {
        if ( (int) $rule['term_id'] === $term_id && ( $rule['taxonomy'] ?? 'product_cat' ) === $taxonomy ) {
            return array_merge( $rule, [ 'id' => $id ] );
        }
    }
    return null;
}

/**
 * Salva (crea o aggiorna) una regola. Ritorna il rule_id.
 *
 * @param array $rule {
 *     id?:         string   — se assente, viene generato (creazione)
 *     term_id:     int
 *     taxonomy:    string   — product_cat, product_brand, product_tag
 *     conditions:  array    — formato filter engine
 *     enabled:     bool     — se false, non viene eseguita su hook
 * }
 * @return string rule_id
 */
function gh_smart_save_rule( array $rule ): string {

    $rules = gh_smart_get_rules();

    $rule_id = $rule['id'] ?? 'sr_' . wp_generate_password( 8, false );

    $rules[ $rule_id ] = [
        'term_id'    => (int) ( $rule['term_id'] ?? 0 ),
        'taxonomy'   => sanitize_key( $rule['taxonomy'] ?? 'product_cat' ),
        'conditions' => $rule['conditions'] ?? [],
        'enabled'    => (bool) ( $rule['enabled'] ?? true ),
        'created_at' => $rules[ $rule_id ]['created_at'] ?? current_time( 'mysql' ),
        'last_sync'  => $rules[ $rule_id ]['last_sync'] ?? null,
        'last_count' => $rules[ $rule_id ]['last_count'] ?? 0,
    ];

    update_option( GH_SMART_RULES_KEY, $rules, false );
    return $rule_id;
}

/**
 * Elimina una regola. NON rimuove i prodotti gia assegnati al termine.
 *
 * @param string $rule_id
 * @return bool
 */
function gh_smart_delete_rule( string $rule_id ): bool {
    $rules = gh_smart_get_rules();
    if ( ! isset( $rules[ $rule_id ] ) ) return false;
    unset( $rules[ $rule_id ] );
    update_option( GH_SMART_RULES_KEY, $rules, false );
    return true;
}

// ── SYNC ──────────────────────────────────────────────────────────────────

/**
 * Esegue una regola: trova tutti i prodotti matching e li assegna al termine.
 *
 * Comportamento "assign-only" (safe): aggiunge il termine ai prodotti che
 * matchano le condizioni, ma NON rimuove il termine dai prodotti che non
 * matchano piu. Questo preserva le assegnazioni manuali.
 *
 * @param string $rule_id
 * @return array { matched, assigned, already, term_name }
 */
function gh_smart_sync_rule( string $rule_id ): array {

    $rule = gh_smart_get_rule( $rule_id );
    if ( ! $rule ) {
        return [ 'error' => "Regola {$rule_id} non trovata." ];
    }

    $term_id    = (int) $rule['term_id'];
    $taxonomy   = $rule['taxonomy'] ?? 'product_cat';
    $conditions = $rule['conditions'] ?? [];

    if ( empty( $conditions ) ) {
        return [ 'error' => 'Nessuna condizione definita.' ];
    }

    $term = get_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) || ! $term ) {
        return [ 'error' => "Termine #{$term_id} non trovato in {$taxonomy}." ];
    }

    // Usa il filter engine esistente per trovare i prodotti corrispondenti
    $matching_ids = gh_filter_product_ids( $conditions );

    $assigned = 0;
    $already  = 0;

    foreach ( $matching_ids as $pid ) {
        $current = wp_get_post_terms( $pid, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $current ) ) continue;

        if ( in_array( $term_id, $current, true ) ) {
            $already++;
        } else {
            // Append: aggiunge senza rimuovere le assegnazioni esistenti
            wp_set_object_terms( $pid, [ $term_id ], $taxonomy, true );
            $assigned++;
        }
    }

    // Aggiorna last_sync nella regola
    $rules = gh_smart_get_rules();
    if ( isset( $rules[ $rule_id ] ) ) {
        $rules[ $rule_id ]['last_sync']  = current_time( 'mysql' );
        $rules[ $rule_id ]['last_count'] = count( $matching_ids );
        update_option( GH_SMART_RULES_KEY, $rules, false );
    }

    return [
        'matched'   => count( $matching_ids ),
        'assigned'  => $assigned,
        'already'   => $already,
        'term_name' => $term->name,
    ];
}

/**
 * Preview: quanti prodotti corrispondono alle condizioni? Dry-run senza scrivere.
 *
 * @param array $conditions
 * @return int
 */
function gh_smart_preview_count( array $conditions ): int {
    if ( empty( $conditions ) ) return 0;
    return count( gh_filter_product_ids( $conditions ) );
}

/**
 * Esegue tutte le regole abilitate. Utile per un sync globale.
 *
 * @return array [ rule_id => result ]
 */
function gh_smart_sync_all(): array {
    $results = [];
    foreach ( gh_smart_get_rules() as $id => $rule ) {
        if ( empty( $rule['enabled'] ) ) continue;
        $results[ $id ] = gh_smart_sync_rule( $id );
    }
    return $results;
}

// ── AUTO-ASSIGN HOOK ──────────────────────────────────────────────────────

/**
 * Valuta tutte le regole abilitate per un singolo prodotto.
 * Chiamato su woocommerce_new_product e woocommerce_update_product.
 *
 * Leggero: per ogni regola carica il prodotto WC UNA volta e valuta le
 * condizioni in memoria. Se il prodotto matcha, lo assegna al termine.
 * Se non matcha, non fa nulla (assign-only, mai unassign).
 *
 * @param int $product_id
 */
function gh_smart_evaluate_product( int $product_id ): void {

    $product = wc_get_product( $product_id );
    if ( ! $product ) return;

    // Non processare varianti — solo parent
    if ( $product->is_type( 'variation' ) ) return;

    $rules = gh_smart_get_rules();
    $cache = [];

    foreach ( $rules as $rule ) {
        if ( empty( $rule['enabled'] ) ) continue;

        $conditions = $rule['conditions'] ?? [];
        if ( empty( $conditions ) ) continue;

        // Valuta ogni condizione
        $match = true;
        foreach ( $conditions as $cond ) {
            if ( ! gh_evaluate_condition( $product, $cond, $cache ) ) {
                $match = false;
                break;
            }
        }

        if ( $match ) {
            $term_id  = (int) $rule['term_id'];
            $taxonomy = $rule['taxonomy'] ?? 'product_cat';

            $current = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $current ) && ! in_array( $term_id, $current, true ) ) {
                wp_set_object_terms( $product_id, [ $term_id ], $taxonomy, true );
            }
        }
    }
}

// Hook: auto-assign su creazione e aggiornamento prodotto
add_action( 'woocommerce_new_product',    'gh_smart_evaluate_product' );
add_action( 'woocommerce_update_product', 'gh_smart_evaluate_product' );
