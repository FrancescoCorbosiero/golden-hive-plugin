# ARCHITECTURE.md — RP Product Manager

## Principio Guida

Il plugin è costruito su **separazione netta dei layer**. Ogni file ha una responsabilità unica e non la condivide con nessun altro.

```
┌─────────────────────────────────────────────────────────┐
│                     WP Admin Browser                    │
│              (HTML + CSS + Vanilla JS)                  │
│                    admin-page.php                       │
└────────────────────────┬────────────────────────────────┘
                         │ fetch() FormData AJAX
                         ▼
┌─────────────────────────────────────────────────────────┐
│                    AJAX Bridge                          │
│  sanitize → validate nonce → call rp_* → json response  │
│                     ajax.php                            │
└──────────┬──────────────────────────┬───────────────────┘
           │                          │
           ▼                          ▼
┌──────────────────┐      ┌───────────────────────────────┐
│    crud.php       │      │        variations.php         │
│                  │      │                               │
│ rp_get_product   │      │ rp_search_products            │
│ rp_create_product│      │ rp_get_product_variations     │
│ rp_update_product│      │ rp_update_variation           │
│ rp_delete_product│      │ rp_bulk_update_variations     │
└──────────┬───────┘      └──────────────┬────────────────┘
           │                             │
           └──────────────┬──────────────┘
                          ▼
┌─────────────────────────────────────────────────────────┐
│                   WooCommerce API                        │
│   wc_get_product() / WC_Product_Simple / WC_Product_    │
│   Variable / wc_get_product_id_by_sku() / etc.          │
└─────────────────────────────────────────────────────────┘
```

---

## Flusso di una Richiesta AJAX

Esempio: l'utente clicca "Salva modifiche" sulle varianti.

```
1. UI (admin-page.php JS)
   └─ Costruisce array di updates da state.dirty
   └─ fetch(AJAX_URL, {action: 'rp_ajax_save_variations', updates: JSON, product_id: X})

2. ajax.php — wp_ajax_rp_ajax_save_variations
   └─ check_ajax_referer('rp_crud_nonce', 'nonce')       ← sicurezza
   └─ current_user_can('manage_woocommerce')              ← autorizzazione
   └─ json_decode($_POST['updates'])                      ← sanitize
   └─ rp_bulk_update_variations($updates)                 ← delega
   └─ rp_get_product_variations($product_id)              ← ricarica stato fresco
   └─ wp_send_json_success([results, errors, variations]) ← risposta

3. variations.php — rp_bulk_update_variations()
   └─ Loop su ogni update
   └─ rp_update_variation($var_id, $fields)
       └─ wc_get_product($variation_id)
       └─ $v->set_regular_price() / set_stock_quantity() / etc.
       └─ $v->save()
       └─ WC_Product_Variable::sync($parent_id)          ← aggiorna padre

4. UI (admin-page.php JS)
   └─ Aggiorna state.dirty = {}
   └─ Re-render tabella con dati freschi
   └─ Toast "Salvate N modifiche"
```

---

## Gestione dello Stato UI

Lo stato dell'applicazione è centralizzato in un oggetto `state`:

```javascript
let state = {
    product:    null,        // Oggetto prodotto corrente (da rp_get_product)
    variations: [],          // Array varianti (da rp_get_product_variations)
    dirty:      {},          // { variation_id: { field: newValue, ... } }
    selected:   new Set(),   // Set di variation_id selezionati per bulk ops
};
```

### Dirty Tracking

Quando l'utente modifica una cella nella tabella varianti:
1. `markDirty(vid, field, value)` viene chiamata
2. Aggiunge `{ [field]: value }` a `state.dirty[vid]`
3. La riga viene evidenziata in arancione (classe CSS `dirty`)
4. Il badge "N modifiche non salvate" diventa visibile
5. Il bottone "Salva" viene abilitato

Al salvataggio, `state.dirty` viene trasformato in array per la AJAX call e poi azzerato.

### Perché non usare un framework (React/Vue)

- Zero toolchain → il plugin si deploya come file PHP, nessun `npm build`
- WP Admin ha già jQuery caricato — non vogliamo conflitti
- La UI è sufficientemente semplice: una tabella + tre tab
- Se la complessità cresce abbastanza, migrare a un componente React è una decisione separata

---

## Sicurezza

Ogni AJAX handler implementa una doppia protezione:

```php
// 1. Nonce check — verifica che la richiesta venga dalla nostra pagina
check_ajax_referer('rp_crud_nonce', 'nonce');

// 2. Capability check — verifica che l'utente abbia i permessi giusti
if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
```

Il nonce `rp_crud_nonce` viene generato server-side con `wp_create_nonce()` nel render della pagina e iniettato nel JS come variabile PHP inline. Ha scadenza di 12 ore (default WP).

---

## WooCommerce Quirks da Ricordare

### Prezzi come stringhe
WooCommerce salva i prezzi come stringhe, non float.
```php
// CORRETTO
$product->set_regular_price('249.00');

// SBAGLIATO (può causare arrotondamenti)
$product->set_regular_price(249.0);
```

### Sale price vuoto vs null
Per rimuovere il prezzo scontato, passare stringa vuota `''`, non `null`.
```php
$v->set_sale_price('');  // Rimuove il saldo ✓
$v->set_sale_price(null); // Comportamento imprevedibile ✗
```

### Sync prodotto variabile
Dopo aver modificato una variante, bisogna sempre chiamare:
```php
WC_Product_Variable::sync($parent_id);
```
Altrimenti il prezzo mostrato sul prodotto padre rimane quello vecchio.

### array_key_exists vs isset per update selettivo
```php
// isset() ritorna false se il valore è null o ''
// array_key_exists() ritorna true anche per null e ''
// Per update selettivi, usare SEMPRE array_key_exists:

if (array_key_exists('sale_price', $data)) {
    $product->set_sale_price($data['sale_price']); // Passa anche ''
}
```
