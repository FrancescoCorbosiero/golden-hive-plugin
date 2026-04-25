<?php
/**
 * Conflict Engine — risolve quali slice di un update sono permesse.
 *
 * Input:
 *  - product_id   (per leggere la provenance corrente)
 *  - incoming     (shape woo data — non modifica, serve per context)
 *  - incoming_src (la source che sta provando a scrivere)
 *
 * Output:
 *  - allowed_slices: [catalog, pricing, stock, media] => bool
 *  - blocked:        slice => reason
 *  - applied_rule:   id della rule che ha fatto match (o null → default)
 *
 * Algoritmo:
 *  1. Legge i sources correnti del prodotto + field_sources.
 *  2. Se il prodotto non ha alcun source registrato (nuovo / mai toccato),
 *     e l'incoming e l'UNICA source → tutto allowed.
 *  3. Itera le rule in ordine di priority asc. Prima rule che matcha applica
 *     il suo 'then'. Se stop_on_match, return.
 *  4. Se nessuna rule matcha → default 'allow' per tutto.
 *
 * Matching di una rule:
 *  - when.sources_contains: TUTTE devono essere presenti nei current sources
 *  - when.sources_any: ALMENO UNA deve esserci (skip se lista vuota)
 *  - when.incoming: se non vuota, incoming_src deve esserci
 *
 * Valore 'then' per una slice:
 *  - 'allow'      → true
 *  - 'block'      → false
 *  - '<source>'   → true se incoming_src === <source>, altrimenti false
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_conflict_resolve' ) ) return;

/**
 * Risolve quali slice possono essere scritte.
 *
 * @param int    $product_id
 * @param array  $incoming      Payload incoming (non modificato; informativo).
 * @param string $incoming_src  Identificatore della source (es. 'kicksdb').
 * @param array  $opts {
 *   @type bool $overwrite_manual  Se true, bypassa la rule 'manual sacred'.
 * }
 * @return array {
 *   allowed_slices: [slice => bool],
 *   blocked:        [slice => reason],
 *   applied_rule:   string|null,
 *   current_sources: string[],
 * }
 */
function gh_conflict_resolve( int $product_id, array $incoming, string $incoming_src, array $opts = [] ): array {

    $allow_all = [ 'catalog' => true, 'pricing' => true, 'stock' => true, 'media' => true ];
    $result = [
        'allowed_slices'  => $allow_all,
        'blocked'         => [],
        'applied_rule'    => null,
        'current_sources' => [],
    ];

    if ( $product_id <= 0 || $incoming_src === '' ) {
        return $result;
    }

    $current_sources = gh_conflict_get_source_names( $product_id );
    $result['current_sources'] = $current_sources;

    // Prodotto nuovo / primo touch: allow tutto
    if ( empty( $current_sources ) ) {
        return $result;
    }

    // Prodotto gia tracciato SOLO da incoming_src stessa → allow tutto
    if ( count( $current_sources ) === 1 && $current_sources[0] === $incoming_src ) {
        return $result;
    }

    $rules = gh_conflict_rules_all();
    $override_manual = ! empty( $opts['overwrite_manual'] );

    foreach ( $rules as $rule ) {
        if ( empty( $rule['enabled'] ) ) continue;

        // Override esplicito utente per rule 'manual_sacred'
        if ( $override_manual && ( $rule['id'] ?? '' ) === 'rule_manual_sacred' ) continue;

        if ( ! gh_conflict_rule_matches( $rule, $current_sources, $incoming_src ) ) continue;

        $result['applied_rule'] = (string) $rule['id'];
        $then = (array) ( $rule['then'] ?? [] );

        foreach ( GH_CONFLICT_SLICES as $slice ) {
            $directive = (string) ( $then[ $slice ] ?? 'allow' );
            $allowed = gh_conflict_evaluate_directive( $directive, $incoming_src );
            $result['allowed_slices'][ $slice ] = $allowed;
            if ( ! $allowed ) {
                $result['blocked'][ $slice ] = 'rule: ' . ( $rule['label'] ?? $rule['id'] );
            }
        }

        if ( ! empty( $rule['stop_on_match'] ) ) break;
    }

    return $result;
}

/**
 * Valuta se una rule matcha il contesto corrente.
 */
function gh_conflict_rule_matches( array $rule, array $current_sources, string $incoming_src ): bool {

    $when = (array) ( $rule['when'] ?? [] );
    $contains = (array) ( $when['sources_contains'] ?? [] );
    $any      = (array) ( $when['sources_any'] ?? [] );
    $incoming = (array) ( $when['incoming'] ?? [] );

    if ( ! empty( $contains ) ) {
        foreach ( $contains as $s ) {
            if ( ! in_array( $s, $current_sources, true ) ) return false;
        }
    }

    if ( ! empty( $any ) ) {
        $ok = false;
        foreach ( $any as $s ) {
            if ( in_array( $s, $current_sources, true ) ) { $ok = true; break; }
        }
        if ( ! $ok ) return false;
    }

    if ( ! empty( $incoming ) ) {
        if ( ! in_array( $incoming_src, $incoming, true ) ) return false;
    }

    return true;
}

/**
 * 'allow' → true, 'block' → false, '<source>' → incoming === source.
 */
function gh_conflict_evaluate_directive( string $directive, string $incoming_src ): bool {
    if ( $directive === '' || $directive === 'allow' ) return true;
    if ( $directive === 'block' )                        return false;
    return $directive === $incoming_src;
}

/**
 * Dry-run preview di una rule set su N prodotti. Utile per l'UI delle rules.
 *
 * @param int[]  $product_ids
 * @param string $incoming_src
 * @return array Per ogni prodotto: { id, sku, name, sources, allowed_slices, applied_rule }
 */
function gh_conflict_dry_run( array $product_ids, string $incoming_src ): array {

    $out = [];
    foreach ( $product_ids as $pid ) {
        $pid = (int) $pid;
        if ( $pid <= 0 ) continue;
        $p = wc_get_product( $pid );
        if ( ! $p ) continue;

        $res = gh_conflict_resolve( $pid, [], $incoming_src );
        $out[] = [
            'id'             => $pid,
            'sku'            => $p->get_sku(),
            'name'           => $p->get_name(),
            'sources'        => $res['current_sources'],
            'allowed_slices' => $res['allowed_slices'],
            'applied_rule'   => $res['applied_rule'],
            'blocked'        => $res['blocked'],
        ];
    }
    return $out;
}
