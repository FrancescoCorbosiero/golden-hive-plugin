<?php
/**
 * Conflict Rules storage — persistenza ordinata delle rule per risolvere il
 * conflitto tra feed diversi che toccano lo stesso SKU.
 *
 * Shape di una rule:
 * {
 *   id: "rule_xxx",
 *   label: "Manual always wins",
 *   enabled: true,
 *   priority: 10,
 *   when: {
 *     sources_contains: [ "manual" ],         // tutte devono esserci
 *     sources_any:      [],                    // almeno una
 *     incoming:         [ "kicksdb" ],         // match su quale source sta facendo l'update
 *   },
 *   then: {
 *     catalog: "block"   | "manual" | "kicksdb" | "incoming",
 *     pricing: "allow"   | ...,
 *     stock:   "allow",
 *     media:   "block"
 *   },
 *   stop_on_match: true   // se true, non valuta le rule successive
 * }
 *
 * Values per slice:
 * - "allow"      → il source incoming scrive sulla slice (default behaviour)
 * - "block"      → skip
 * - "<source>"   → scrivi solo se incoming === <source> (pin strict)
 *
 * Built via gh_option_list_* (CRUD generico).
 */

defined( 'ABSPATH' ) || exit;

// Const SOPRA il guard: ogni inclusione del file deve garantire che la
// costante esista — anche se un'inclusione successiva trova il guard
// soddisfatto e ritorna early. Usato cross-file da migrate.php (vedi
// gh_conflict_install_default_rules), quindi non puo essere "internal".
defined( 'GH_CONFLICT_RULES_KEY' ) || define( 'GH_CONFLICT_RULES_KEY', 'gh_conflict_rules' );

if ( function_exists( 'gh_conflict_rules_all' ) ) return;

/**
 * Ritorna tutte le rule ordinate per priority asc.
 */
function gh_conflict_rules_all(): array {
    $rules = gh_option_list_all( GH_CONFLICT_RULES_KEY );
    usort( $rules, fn( $a, $b ) =>
        (int) ( $a['priority'] ?? 100 ) <=> (int) ( $b['priority'] ?? 100 )
    );
    return $rules;
}

function gh_conflict_rules_find( string $id ): ?array {
    return gh_option_list_find( GH_CONFLICT_RULES_KEY, $id );
}

/**
 * Upsert rule. Normalizza shape + sanitize.
 */
function gh_conflict_rules_upsert( array $data ): string {
    return gh_option_list_upsert(
        GH_CONFLICT_RULES_KEY,
        $data,
        'id',
        'rule_',
        'gh_conflict_rule_sanitize',
        true
    );
}

function gh_conflict_rules_remove( string $id ): bool {
    return gh_option_list_remove( GH_CONFLICT_RULES_KEY, $id );
}

/**
 * Sanitize + normalize shape rule.
 */
function gh_conflict_rule_sanitize( array $r ): array {
    $out = [
        'id'       => (string) ( $r['id'] ?? '' ),
        'label'    => sanitize_text_field( (string) ( $r['label'] ?? 'Rule' ) ),
        'enabled'  => ! isset( $r['enabled'] ) || (bool) $r['enabled'],
        'priority' => max( 0, min( 9999, (int) ( $r['priority'] ?? 100 ) ) ),
        'when'     => [],
        'then'     => [],
        'stop_on_match' => (bool) ( $r['stop_on_match'] ?? true ),
    ];

    $when = (array) ( $r['when'] ?? [] );
    $out['when'] = [
        'sources_contains' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $when['sources_contains'] ?? [] ) ) ) ),
        'sources_any'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $when['sources_any'] ?? [] ) ) ) ),
        'incoming'         => array_values( array_filter( array_map( 'sanitize_key', (array) ( $when['incoming'] ?? [] ) ) ) ),
    ];

    $then = (array) ( $r['then'] ?? [] );
    foreach ( GH_CONFLICT_SLICES as $slice ) {
        $v = (string) ( $then[ $slice ] ?? 'allow' );
        // accettiamo allow | block | <source name>
        $out['then'][ $slice ] = $v !== '' ? sanitize_key( $v ) : 'allow';
    }

    return $out;
}

/**
 * Reset hard (per testing / reinstall). Non uso in produzione.
 */
function gh_conflict_rules_reset_to_defaults(): void {
    gh_option_list_replace( GH_CONFLICT_RULES_KEY, gh_conflict_default_rules() );
}

/**
 * Set di default shipped con il plugin. Sicuri per siti esistenti:
 *
 * Rule 1 — "Manual is sacred" (priority 10):
 *   if sources_contains ['manual'] → block tutte le slice
 *   (nessuna source altra puo toccare un prodotto manuale)
 *
 * Rule 2 — "GS owns pricing when both" (priority 20):
 *   if sources_contains ['goldensneakers', 'kicksdb'] → KicksDB puo scrivere
 *   catalog + media; GS tiene pricing + stock.
 *
 * Rule 3 (fallback implicito) — default "allow" per tutte le slice.
 *
 * La rule 1 e la garanzia di sicurezza per prodotti gia presenti sul sito
 * PRIMA di KicksDB.
 */
function gh_conflict_default_rules(): array {
    $now = current_time( 'mysql' );
    return [
        [
            'id'       => 'rule_manual_sacred',
            'label'    => 'Manual source is sacred',
            'enabled'  => true,
            'priority' => 10,
            'when'     => [ 'sources_contains' => [ 'manual' ], 'sources_any' => [], 'incoming' => [] ],
            'then'     => [ 'catalog' => 'block', 'pricing' => 'block', 'stock' => 'block', 'media' => 'block' ],
            'stop_on_match' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id'       => 'rule_gs_owns_pricing',
            'label'    => 'GS owns pricing+stock, KicksDB owns catalog+media',
            'enabled'  => true,
            'priority' => 20,
            'when'     => [ 'sources_contains' => [ 'goldensneakers' ], 'sources_any' => [], 'incoming' => [ 'kicksdb' ] ],
            'then'     => [ 'catalog' => 'allow', 'pricing' => 'block', 'stock' => 'block', 'media' => 'allow' ],
            'stop_on_match' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ];
}
