<?php
/**
 * Navigation Manager — letture/scritture safe sui WordPress Nav Menus.
 *
 * Thin layer sull'API di WP (`wp_get_nav_menus`, `wp_get_nav_menu_items`,
 * `wp_update_nav_menu_item`, `wp_delete_post`) con due feature in piu:
 *
 *   1. Popolamento automatico di un item (es. "Abbigliamento") a partire da
 *      un set di termini — ogni item creato viene taggato con il marker
 *      `GH_NAV_MANAGED_META` cosi gli item fatti a mano restano intoccati.
 *   2. "Clear managed children" rimuove solo gli item taggati; quelli creati
 *      manualmente dall'utente non vengono toccati.
 *
 * Prefix funzioni: `gh_nav_`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post meta che marca gli item creati automaticamente da Hive Commerce.
 * Presente solo sui `nav_menu_item` generati da `gh_nav_populate_from_terms()`.
 */
const GH_NAV_MANAGED_META = '_gh_nav_managed';

/**
 * Lista menu registrati con conteggio item.
 *
 * @return array[] [ { id, name, slug, count, locations: string[] } ]
 */
function gh_nav_get_menus(): array {

    $menus     = wp_get_nav_menus();
    $locations = array_flip( (array) get_nav_menu_locations() );

    $out = [];
    foreach ( $menus as $m ) {
        $count = is_wp_error( $m ) ? 0 : (int) $m->count;
        $out[] = [
            'id'        => (int) $m->term_id,
            'name'      => $m->name,
            'slug'      => $m->slug,
            'count'     => $count,
            'locations' => array_keys( array_filter( $locations, fn( $tid ) => (int) $tid === (int) $m->term_id ) ),
        ];
    }
    return $out;
}

/**
 * Ritorna gli item di un menu flat (mantiene l'ordine WP) arricchiti con un
 * flag `managed` che indica se l'item e stato generato da Hive Commerce.
 *
 * @param int $menu_id
 * @return array[] ogni item: { id, parent, order, title, type, object, object_id, url, managed }
 */
function gh_nav_get_menu_items( int $menu_id ): array {

    if ( ! $menu_id ) return [];

    $items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );
    if ( ! is_array( $items ) ) return [];

    $out = [];
    foreach ( $items as $it ) {
        $managed = (int) get_post_meta( $it->ID, GH_NAV_MANAGED_META, true ) === 1;
        $out[] = [
            'id'        => (int) $it->ID,
            'parent'    => (int) $it->menu_item_parent,
            'order'     => (int) $it->menu_order,
            'title'     => (string) $it->title,
            'type'      => (string) $it->type,            // post_type | taxonomy | custom
            'object'    => (string) $it->object,          // product_cat | page | ...
            'object_id' => (int) $it->object_id,
            'url'       => (string) $it->url,
            'managed'   => $managed,
        ];
    }
    return $out;
}

/**
 * Crea (o aggiorna) un menu item. Thin wrapper su `wp_update_nav_menu_item`
 * con sanitizzazione della shape attesa.
 *
 * @param int   $menu_id
 * @param array $data {
 *     @type int    $item_id      0 per create, >0 per update.
 *     @type string $title
 *     @type string $type         post_type | taxonomy | custom (default: custom).
 *     @type string $object       es. product_cat.
 *     @type int    $object_id    term_id o post_id per tipo taxonomy/post_type.
 *     @type string $url          solo per type=custom.
 *     @type int    $parent       menu_item_parent.
 *     @type int    $position     menu_order.
 *     @type string $status       publish|draft (default: publish).
 *     @type bool   $managed      se true marca con GH_NAV_MANAGED_META.
 * }
 * @return int|WP_Error item_id creato/aggiornato.
 */
function gh_nav_upsert_item( int $menu_id, array $data ) {

    if ( ! $menu_id ) return new \WP_Error( 'no_menu', 'menu_id mancante.' );

    $type = in_array( $data['type'] ?? 'custom', [ 'post_type', 'taxonomy', 'custom' ], true ) ? $data['type'] : 'custom';

    $payload = [
        'menu-item-title'     => (string) ( $data['title'] ?? '' ),
        'menu-item-type'      => $type,
        'menu-item-object'    => (string) ( $data['object'] ?? '' ),
        'menu-item-object-id' => (int) ( $data['object_id'] ?? 0 ),
        'menu-item-url'       => (string) ( $data['url'] ?? '' ),
        'menu-item-parent-id' => (int) ( $data['parent'] ?? 0 ),
        'menu-item-position'  => (int) ( $data['position'] ?? 0 ),
        'menu-item-status'    => in_array( $data['status'] ?? 'publish', [ 'publish', 'draft' ], true ) ? $data['status'] : 'publish',
    ];

    $item_id = (int) ( $data['item_id'] ?? 0 );
    $result  = wp_update_nav_menu_item( $menu_id, $item_id, $payload );

    if ( is_wp_error( $result ) ) return $result;
    if ( ! $result ) return new \WP_Error( 'nav_upsert_failed', 'Creazione item fallita.' );

    if ( ! empty( $data['managed'] ) ) {
        update_post_meta( $result, GH_NAV_MANAGED_META, 1 );
    }
    return (int) $result;
}

/**
 * Elimina un item. WP cancella anche i figli diretti che puntano a questo
 * parent (via ri-parent a 0 di default); noi invece manteniamo integrita
 * restituendo il caller decide.
 *
 * @param int $item_id
 * @return bool
 */
function gh_nav_delete_item( int $item_id ): bool {
    if ( ! $item_id ) return false;
    $ok = wp_delete_post( $item_id, true );
    return (bool) $ok;
}

/**
 * Rimuove tutti gli item managed (creati automaticamente) che sono figli
 * diretti di un parent. Gli item hand-crafted restano.
 *
 * @param int $menu_id
 * @param int $parent_item_id  0 = root-level managed items
 * @return int numero item eliminati.
 */
function gh_nav_clear_managed_children( int $menu_id, int $parent_item_id ): int {

    $items = gh_nav_get_menu_items( $menu_id );
    $deleted = 0;
    foreach ( $items as $it ) {
        if ( $it['parent'] !== $parent_item_id ) continue;
        if ( ! $it['managed'] ) continue;
        if ( gh_nav_delete_item( $it['id'] ) ) $deleted++;
    }
    return $deleted;
}

/**
 * Popola (one-shot) un item del menu con un set di termini come figli.
 *
 * Comportamento non distruttivo:
 *   - `replace_managed=true` rimuove prima SOLO i figli con marker GH managed.
 *   - Gli item fatti a mano sotto lo stesso parent restano intatti.
 *
 * I nuovi item vengono aggiunti dopo l'item con menu_order piu alto tra i
 * figli esistenti di $parent_item_id, in blocchi di +10.
 *
 * @param int    $menu_id
 * @param int    $parent_item_id id del menu-item sotto cui appendere (0 = root).
 * @param int[]  $term_ids       termini da aggiungere come figli.
 * @param string $taxonomy       es. product_cat.
 * @param array  $options {
 *     @type bool $replace_managed  rimuovi prima i managed esistenti (default: true).
 * }
 * @return array { created: int[], removed: int, skipped: int, errors: string[] }
 */
function gh_nav_populate_from_terms( int $menu_id, int $parent_item_id, array $term_ids, string $taxonomy, array $options = [] ): array {

    $out = [ 'created' => [], 'removed' => 0, 'skipped' => 0, 'errors' => [] ];

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );
    $term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
    if ( ! $menu_id || ! $term_ids ) {
        $out['errors'][] = 'Parametri mancanti (menu_id o term_ids vuoti).';
        return $out;
    }

    if ( ! empty( $options['replace_managed'] ?? true ) ) {
        $out['removed'] = gh_nav_clear_managed_children( $menu_id, $parent_item_id );
    }

    // Determine starting position: after the current max menu_order among
    // siblings at parent_item_id. We read fresh after the clear step.
    $items = gh_nav_get_menu_items( $menu_id );
    $max_order = 0;
    foreach ( $items as $it ) {
        if ( $it['parent'] === $parent_item_id && $it['order'] > $max_order ) {
            $max_order = $it['order'];
        }
    }

    $cursor = $max_order + 10;

    foreach ( $term_ids as $tid ) {
        $term = get_term( $tid, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            $out['skipped']++;
            $out['errors'][] = "Termine #{$tid} non trovato in {$taxonomy}.";
            continue;
        }

        $r = gh_nav_upsert_item( $menu_id, [
            'title'     => $term->name,
            'type'      => 'taxonomy',
            'object'    => $taxonomy,
            'object_id' => (int) $term->term_id,
            'parent'    => $parent_item_id,
            'position'  => $cursor,
            'status'    => 'publish',
            'managed'   => true,
        ] );
        if ( is_wp_error( $r ) ) {
            $out['skipped']++;
            $out['errors'][] = $r->get_error_message();
        } else {
            $out['created'][] = (int) $r;
            $cursor += 10;
        }
    }

    return $out;
}
