# ARCHITECTURE.md — RP Media Cleaner

## Flusso Principale: Scansione

```
┌─────────────────────────────────────────────────────────┐
│                     Admin UI                            │
│              "Avvia Scansione" button                   │
└────────────────────────┬────────────────────────────────┘
                         │ AJAX: rp_mc_ajax_scan
                         ▼
┌─────────────────────────────────────────────────────────┐
│                    ajax.php                             │
│         check_ajax_referer + manage_options             │
└──────────┬──────────────────────────────────────────────┘
           │
           ▼
┌──────────────────────────────────────────────────────────────┐
│                      scanner.php                             │
│                                                              │
│  rp_mc_get_all_attachments()                                 │
│    └─ get_posts(post_type='attachment', posts_per_page=-1)   │
│       + wp_get_attachment_url() per ogni ID                  │
│       + filesize() per ogni file fisico                      │
│                                                              │
│  rp_mc_get_used_attachment_ids()                             │
│    ├─ WooCommerce: get_posts(post_type='product')            │
│    │   └─ per ogni prodotto:                                 │
│    │       ├─ get_post_thumbnail_id()    → featured image    │
│    │       └─ get_post_meta(_product_image_gallery) → CSV    │
│    ├─ WordPress posts/pages:                                 │
│    │   └─ get_post_thumbnail_id() per ogni post              │
│    └─ Content inline: regex su post_content                  │
│        └─ attachment_url_to_postid() per ogni src trovato    │
│                                                              │
│  DIFF: all_ids - used_ids - whitelisted_ids = orphan_ids    │
└──────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────┐
│  Response al client:                                    │
│  { orphans: [...], used_count, orphan_count, size_mb }  │
└─────────────────────────────────────────────────────────┘
```

---

## Flusso: Eliminazione con Whitelist Check

```
UI seleziona N orfani → "Elimina selezionati" → mostra recap → conferma
                                                                    │
                                                          AJAX: rp_mc_ajax_bulk_delete
                                                                    │
                                                            ajax.php
                                                                    │
                                                    rp_mc_bulk_delete([ids])
                                                                    │
                                                    Per ogni id:
                                                      1. rp_mc_is_whitelisted(id)
                                                         └─ SE TRUE: skip, aggiungi a skipped_whitelist[]
                                                      2. rp_mc_is_used(id)
                                                         └─ SE TRUE: skip, aggiungi a errors[id]
                                                      3. wp_delete_attachment(id, true)
                                                         └─ SE OK: aggiungi a deleted[], logga evento
                                                         └─ SE FAIL: aggiungi a errors[id]
                                                                    │
                                                    Response: { deleted, errors, skipped_whitelist, freed_bytes }
                                                                    │
                                                    UI aggiorna tabella (rimuove deleted, mantiene errors)
```

---

## Struttura Dati

### Orphan object (risposta scanner)
```json
{
  "id": 1234,
  "url": "https://resellpiacenza.it/wp-content/uploads/2024/01/nike-dunk-test.jpg",
  "filename": "nike-dunk-test.jpg",
  "filesize": 245760,
  "filesize_human": "240 KB",
  "date": "2024-01-15 10:23:00",
  "mime_type": "image/jpeg",
  "thumbnail_url": "https://resellpiacenza.it/wp-content/uploads/2024/01/nike-dunk-test-150x150.jpg",
  "is_whitelisted": false,
  "whitelist_reason": null
}
```

### Whitelist entry (wp_options: rp_mc_whitelist)
```json
[
  {
    "id": 567,
    "url": "https://resellpiacenza.it/wp-content/uploads/logo.png",
    "reason": "Logo sito - usato in header e email",
    "added_at": "2025-01-10 14:30:00",
    "added_by": 1
  }
]
```

### Deletion log entry (wp_options: rp_mc_deletion_log)
```json
{
  "attachment_id": 1234,
  "filename": "nike-dunk-test.jpg",
  "url": "https://resellpiacenza.it/wp-content/uploads/2024/01/nike-dunk-test.jpg",
  "filesize": 245760,
  "deleted_at": "2025-03-15 11:45:00",
  "deleted_by_user_id": 1,
  "deleted_by_username": "admin"
}
```

---

## Stato UI

```javascript
let state = {
    // Risultati scansione
    allOrphans:  [],       // Array completo da server
    filtered:    [],       // Sottoinsieme dopo filtri UI (mime, size, date)
    selected:    new Set(), // IDs selezionati per bulk delete

    // Whitelist locale (copia client-side per check immediati)
    whitelist:   [],

    // UI state
    scanComplete: false,
    scanning:    false,
    deleting:    false,

    // Filtri attivi
    filters: {
        mime:      'all',    // 'all' | 'image' | 'video' | 'document'
        minSize:   0,        // bytes
        dateAfter: null,
    }
};
```

---

## Performance: Scansione su Librerie Grandi

Per media library con >2000 attachment, la scansione sincrona in una singola AJAX call può andare in timeout.

**Strategia raccomandata (da implementare come P1):**

```
1. Prima call: rp_mc_ajax_scan_init
   └─ conta il totale degli attachment
   └─ ritorna: { total, chunk_size: 200, session_id: 'xxx' }

2. Loop calls: rp_mc_ajax_scan_chunk?session_id=xxx&offset=N
   └─ processa chunk[N..N+200]
   └─ accumula used_ids in transient WordPress (keyed by session_id)
   └─ ritorna: { processed: N, total: T, progress_pct: X }

3. Final call: rp_mc_ajax_scan_finalize?session_id=xxx
   └─ calcola diff finale
   └─ ritorna orphans completo
   └─ pulisce transient
```

L'UI mostra una progress bar durante il processo.

---

## WooCommerce Gallery — Gotcha

La gallery di un prodotto WooCommerce è salvata come meta `_product_image_gallery` con valore stringa CSV di IDs:

```
"1234,5678,9012"
```

```php
// Lettura corretta:
$gallery_ids = array_filter(
    explode(',', get_post_meta($product_id, '_product_image_gallery', true))
);
// array_filter rimuove stringhe vuote (caso gallery vuota → "")
```

Per i prodotti variabili, le varianti possono avere immagini proprie (`_thumbnail_id` sulla variante). Vanno incluse nella scansione.

```php
// Per prodotti variabili, scansiona anche le varianti:
if ($product->is_type('variable')) {
    foreach ($product->get_children() as $variation_id) {
        $var_thumb = get_post_thumbnail_id($variation_id);
        if ($var_thumb) $used_ids[] = $var_thumb;
    }
}
```
