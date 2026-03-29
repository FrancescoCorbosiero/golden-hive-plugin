# ARCHITECTURE.md — RP Catalog Manager

## Flusso Principale: Export CATALOG

```
UI → seleziona filtri → "Genera Catalog" button
        │
        AJAX: rp_cm_ajax_export_catalog
        { filters: { status: 'publish', brand: 'Nike', in_stock: true } }
        │
        ajax.php
        └─ rp_cm_export_catalog($filters)
                │
                ├── 1. reader.php
                │   └─ rp_cm_get_all_products($filters)
                │       └─ WC_Product_Query(['status'=>'publish', ...])
                │           → [WC_Product, WC_Product, ...]
                │
                ├── 2. Per ogni WC_Product:
                │   ├─ reader.php
                │   │   └─ rp_cm_get_product_variants($product->get_id())
                │   │       → [WC_Product_Variation, ...]
                │   │
                │   └─ aggregator.php
                │       └─ rp_cm_aggregate_product($product, $variants)
                │           ├─ rp_cm_extract_sizes($variants)
                │           ├─ rp_cm_calculate_pricing($variants, $product)
                │           ├─ rp_cm_calculate_stock_status($variants)
                │           └─ legge meta Rank Math: rank_math_title, rank_math_focus_keyword
                │           → catalog_entry (vedi DATA_FORMATS.md)
                │
                ├── 3. tree-builder.php
                │   └─ rp_cm_build_tree($catalog_entries)
                │       └─ Per ogni entry:
                │           ├─ rp_cm_get_product_tree_path($product_id)
                │           │   └─ wp_get_post_terms() → categories con parent
                │           │   → ['Sneakers', 'Nike', 'Nike Dunk Low']
                │           └─ $tree['Sneakers']['Nike']['Nike Dunk Low'][] = $entry
                │
                ├── 4. exporter.php
                │   └─ rp_cm_build_summary($tree)
                │       → { total_products, total_in_stock, total_variants, ... }
                │
                └── 5. Ritorna array finale:
                    {
                      generated_at, mode: 'catalog',
                      summary: {...},
                      tree: { Sneakers: { Nike: { ... } } }
                    }
        │
        ajax.php
        └─ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        └─ wp_send_json_success($result)
        │
        UI
        ├─ Mostra JSON nel viewer con syntax highlight
        ├─ Mostra summary card (N prodotti, N in stock, N brand, ...)
        └─ Abilita bottone Download
```

---

## Tree Building — Logica di Categoria

Il tree builder deve risolvere la gerarchia delle categorie WooCommerce in 3 livelli (`Sezione → Marca → Sottocategoria`). WooCommerce usa una tassonomia flat con parent_id — il builder deve ricostruire la gerarchia.

```
Categorie WooCommerce (esempio):
  ID 10: "Sneakers"          (parent: 0)       → Sezione
  ID 20: "Nike"              (parent: 10)      → Marca
  ID 30: "Nike Dunk Low"     (parent: 20)      → Sottocategoria
  ID 31: "Nike Dunk High"    (parent: 20)      → Sottocategoria
  ID 40: "Jordan"            (parent: 10)      → Marca
  ID 50: "Air Jordan 4"      (parent: 40)      → Sottocategoria
```

**Algoritmo `rp_cm_get_product_tree_path()`:**

```php
function rp_cm_get_product_tree_path(int $product_id): array {
    $terms = wp_get_post_terms($product_id, 'product_cat', ['orderby' => 'parent']);
    // Ordina: root per primo

    // Cerca la categoria più profonda (max depth nella gerarchia)
    $deepest = null;
    foreach ($terms as $term) {
        if ($deepest === null || $term->parent !== 0) {
            $deepest = $term;
        }
    }

    // Ricostruisce il path risalendo via parent
    $path = [];
    $current = $deepest;
    while ($current && count($path) < 3) {
        array_unshift($path, $current->name);
        $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
    }

    // Normalizza a 3 livelli con fallback
    return [
        $path[0] ?? 'Uncategorized',  // Sezione
        $path[1] ?? 'Uncategorized',  // Marca
        $path[2] ?? 'General',        // Sottocategoria
    ];
}
```

**Caso edge:** prodotti con più categorie (es. un Jordan che è sia in "Nike" che in "Jordan"). Il builder usa la categoria con la gerarchia più profonda. Se il tie è uguale, usa la prima in ordine alfabetico. Il prodotto appare **una sola volta** nell'albero.

---

## Aggregazione Pricing

```php
function rp_cm_calculate_pricing(array $variants, WC_Product $product): array {
    // Prodotto simple: nessuna variante
    if (empty($variants)) {
        return [
            'regular_min' => (float) $product->get_regular_price(),
            'regular_max' => (float) $product->get_regular_price(),
            'regular_avg' => (float) $product->get_regular_price(),
            'has_sale'    => $product->is_on_sale(),
            'sale_min'    => $product->is_on_sale() ? (float) $product->get_sale_price() : null,
            'sale_max'    => $product->is_on_sale() ? (float) $product->get_sale_price() : null,
            'currency'    => get_woocommerce_currency(),
        ];
    }

    $regular_prices = array_filter(
        array_map(fn($v) => (float) $v->get_regular_price(), $variants),
        fn($p) => $p > 0
    );
    $sale_prices = array_filter(
        array_map(fn($v) => (float) $v->get_sale_price(), $variants),
        fn($p) => $p > 0
    );

    return [
        'regular_min' => $regular_prices ? min($regular_prices) : null,
        'regular_max' => $regular_prices ? max($regular_prices) : null,
        'regular_avg' => $regular_prices ? round(array_sum($regular_prices) / count($regular_prices), 2) : null,
        'has_sale'    => count($sale_prices) > 0,
        'sale_min'    => $sale_prices ? min($sale_prices) : null,
        'sale_max'    => $sale_prices ? max($sale_prices) : null,
        'currency'    => get_woocommerce_currency(),
    ];
}
```

**Nota avg:** per sneakers resell i prezzi per taglia sono spesso identici (stesso prezzo per tutte le taglie). L'avg è comunque utile nei casi di prezzi differenziati per taglia (raro ma presente in alcuni brand).

---

## UI State

```javascript
let state = {
    // Export corrente
    currentExport: null,   // JSON object (non stringa) dell'ultimo export
    currentMode:   null,   // 'catalog' | 'full'

    // Filtri selezionati
    filters: {
        status:   'publish',
        brand:    '',       // '' = tutti
        in_stock: false,
    },

    // Meta UI
    generating: false,
    treeCollapsed: {},      // { 'Sneakers.Nike': true } — stato collapse nodi viewer

    // Paths disponibili per i filtri
    availableBrands: [],    // popolato da rp_cm_ajax_get_tree_paths
};
```

---

## Download JSON nel Browser

Nessuna libreria necessaria:

```javascript
function downloadJSON(data, filename) {
    const json   = JSON.stringify(data, null, 2);
    const blob   = new Blob([json], { type: 'application/json' });
    const url    = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href     = url;
    anchor.download = filename;  // es. 'resellpiacenza-catalog-2025-03-29.json'
    anchor.click();
    URL.revokeObjectURL(url);
}
```

Il filename include sempre la data generazione:
- Catalog: `rp-catalog-YYYY-MM-DD.json`
- Full:    `rp-full-export-YYYY-MM-DD.json`

---

## Differenza CATALOG vs FULL — Guida Rapida

| Aspetto | CATALOG | FULL EXPORT |
|---|---|---|
| Varianti singole | ❌ Aggregate | ✅ Ogni variante completa |
| Descrizioni HTML | ❌ Solo flag `has_description` | ✅ HTML completo |
| Immagini | ❌ Non incluse | ✅ URL featured + gallery |
| Meta SEO | ✅ Parziale (keyword, title) | ✅ Completo |
| Dimensione output | Piccola (~50KB per 200 prodotti) | Grande (~500KB+ per 200 prodotti) |
| Velocità generazione | Veloce (~3s per 200 prodotti) | Lenta (~8s per 200 prodotti) |
| Uso tipico | Panoramica, LLM input, condivisione | Backup, migrazione, debug |
