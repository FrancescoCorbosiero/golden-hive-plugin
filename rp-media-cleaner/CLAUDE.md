# CLAUDE.md — RP Media Cleaner

> Stai lavorando su **rp-media-cleaner**. La root del tuo lavoro è `/rp-media-cleaner/`.
> Non toccare le altre cartelle del monorepo salvo indicazione esplicita.
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin
> 3. `docs/ARCHITECTURE.md`
> 4. `docs/ROADMAP.md`

---

## Contesto del Plugin

**RP Media Cleaner** è un plugin WordPress standalone per ResellPiacenza.

**Problema che risolve:** un e-commerce di sneakers accumula migliaia di immagini nella media library WordPress nel tempo — upload duplicati, immagini di prodotti eliminati, test, ecc. Questo spreca spazio disco sul VPS e rallenta i backup. Il plugin scansiona la libreria, identifica gli "orfani" (attachment non usati da nessun prodotto), li presenta in una UI e permette di eliminarli in sicurezza.

**La whitelist è il cuore del plugin.** Prima di eliminare qualsiasi cosa, ogni attachment viene confrontato con la whitelist. Un'immagine in whitelist è intoccabile, indipendentemente da qualsiasi altra logica. Questa è la feature di sicurezza principale.

**Filosofia:** meglio falsi negativi (non trovare tutti gli orfani) che falsi positivi (eliminare immagini usate). In caso di dubbio, conserva.

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| E-commerce | WooCommerce 8.x (usato per leggere prodotti e gallerie) |
| PHP | 8.0+ |
| Admin UI | Vanilla JS + CSS custom (no framework, stessa scuola di rp-product-manager) |
| Font stack UI | JetBrains Mono + DM Sans (Google Fonts) — coerente con gli altri plugin RP |

---

## Struttura del Plugin

```
rp-media-cleaner/
├── rp-media-cleaner.php         ← Entry point. Solo require_once.
└── includes/
    ├── scanner.php              ← Logica di scansione. Nessun hook, solo funzioni pure.
    ├── whitelist.php            ← CRUD whitelist (salva in wp_options).
    ├── cleaner.php              ← Eliminazione attachment. Solo qui si cancella.
    ├── ajax.php                 ← Tutti i wp_ajax_rp_mc_* handler.
    └── admin-page.php           ← UI admin (menu + render).
```

### Regola fondamentale dei layer

```
scanner.php    →  "Trova" (legge, non scrive mai)
whitelist.php  →  "Proteggi" (gestisce la lista di sicurezza)
cleaner.php    →  "Elimina" (l'unico file che cancella dati, sempre previa whitelist check)
ajax.php       →  "Bridge" (sanitize → chiama funzione → json response)
admin-page.php →  "Mostra" (UI pura, zero logica business)
```

**Regola critica:** `cleaner.php` deve sempre chiamare `rp_mc_is_whitelisted()` prima di qualsiasi `wp_delete_attachment()`. Non ci sono eccezioni.

---

## Funzioni PHP Disponibili

### `scanner.php`

```php
rp_mc_get_all_attachments(): array
// Ritorna tutti gli attachment WP (immagini).
// Ogni elemento: [id, url, filename, filesize, date, mime_type]
// Non fa distinzione tra usati e non usati — solo lista completa.

rp_mc_get_used_attachment_ids(): array
// Ritorna un Set (array deduplicato) di attachment ID effettivamente in uso.
// Fonti controllate:
//   1. Featured image di ogni prodotto WooCommerce (_thumbnail_id)
//   2. Gallery prodotto WooCommerce (_product_image_gallery, CSV di IDs)
//   3. Featured image di post e pagine WordPress
//   4. Immagini inline nel content (post_content) via regex su src
//   5. Immagini inline negli excerpt
// Attenzione: le immagini nel content sono cercate per URL, non per ID.
// La funzione fa il reverse lookup URL→ID via attachment_url_to_postid().

rp_mc_get_orphan_attachments(): array
// Ritorna gli attachment NON presenti in rp_mc_get_used_attachment_ids().
// Esclude automaticamente gli attachment in whitelist.
// Ogni elemento: [id, url, filename, filesize, date, mime_type, whitelist_reason]
// NON elimina nulla. Solo lettura.

rp_mc_estimate_orphan_size(): array
// Ritorna: [count, total_bytes, total_human]
// Calcola la dimensione di tutti i file orfani (file fisico su disco).
// Utile per mostrare "Puoi liberare X MB" nell'UI.
```

### `whitelist.php`

```php
rp_mc_get_whitelist(): array
// Ritorna l'array whitelist corrente da wp_options.
// Formato: [ [id, url, reason, added_at], ... ]
// 'id' può essere null se aggiunto per URL.
// 'url' può essere null se aggiunto per ID.

rp_mc_add_to_whitelist(int|null $id, string|null $url, string $reason = ''): bool
// Aggiunge un attachment alla whitelist.
// Almeno uno tra $id e $url deve essere fornito.
// $reason è opzionale ma consigliato (es. "Logo sito", "Immagine SEO Homepage").
// Idempotente: se già presente, aggiorna solo il reason.

rp_mc_remove_from_whitelist(int $id): bool
// Rimuove dalla whitelist per ID.

rp_mc_is_whitelisted(int $attachment_id): bool
// Check primario di sicurezza. Usato da cleaner.php prima di ogni eliminazione.
// Controlla sia per ID che per URL (per gestire casi edge di URL matching).

rp_mc_clear_whitelist(): bool
// Svuota tutta la whitelist. Richiede conferma nella UI (doppio step).
```

### `cleaner.php`

```php
rp_mc_delete_attachment(int $attachment_id): true|WP_Error
// Elimina UN attachment.
// Step obbligatori (in ordine):
//   1. rp_mc_is_whitelisted($attachment_id) → se true, ritorna WP_Error('whitelisted')
//   2. rp_mc_is_used($attachment_id) → double-check, se usato ritorna WP_Error('in_use')
//   3. wp_delete_attachment($attachment_id, true) → force=true elimina il file fisico
// Logga ogni eliminazione in rp_mc_deletion_log (wp_options, ultimi 500 eventi).

rp_mc_bulk_delete(array $attachment_ids): array
// Elimina N attachment. Non si ferma agli errori.
// Ritorna: { deleted: [ids], errors: {id: 'reason'}, skipped_whitelist: [ids] }

rp_mc_get_deletion_log(int $limit = 100): array
// Ritorna gli ultimi N eventi di eliminazione.
// Ogni evento: [attachment_id, filename, url, deleted_at, deleted_by_user_id]

rp_mc_is_used(int $attachment_id): bool
// Helper interno. Ri-controlla se un attachment è in uso al momento dell'eliminazione.
// Separato da scanner per essere chiamato in modo puntuale senza ricaricare tutto.
```

---

## AJAX Endpoints

Tutti richiedono nonce `rp_mc_nonce` + capability `manage_options`.

| Action | Parametri POST | Risposta |
|---|---|---|
| `rp_mc_ajax_scan` | — | `{attachments, orphans, used_count, orphan_count, estimated_size}` |
| `rp_mc_ajax_get_whitelist` | — | array whitelist |
| `rp_mc_ajax_add_whitelist` | `attachment_id`, `url`, `reason` | `{success, whitelist}` |
| `rp_mc_ajax_remove_whitelist` | `attachment_id` | `{success, whitelist}` |
| `rp_mc_ajax_delete_one` | `attachment_id` | `{success, freed_bytes}` |
| `rp_mc_ajax_bulk_delete` | `ids` (JSON array) | `{deleted, errors, skipped_whitelist, freed_bytes}` |
| `rp_mc_ajax_get_log` | `limit` (opt.) | array log eventi |

---

## Convenzioni di Codice

### PHP
- Prefix **`rp_mc_`** su tutte le funzioni pubbliche.
- La whitelist è salvata in `wp_options` con chiave `rp_mc_whitelist`. Serializzata come JSON.
- Il log è salvato in `wp_options` con chiave `rp_mc_deletion_log`. Max 500 entry (FIFO).
- `wp_delete_attachment($id, true)` — sempre `true` come secondo argomento (forza eliminazione file fisico, non solo il record DB).
- La scansione può essere lenta su librerie grandi. Per librerie >2000 attachment, implementare la scansione in chunk con offset paginato (vedi ROADMAP).

### UI Behaviour
- La UI deve mostrare **anteprime thumbnail** degli orfani, non solo filename.
- Ogni orfano ha un checkbox per la selezione bulk.
- Il bottone "Elimina selezionati" deve avere un **doppio step di conferma** (mostra count + size da liberare, poi conferma).
- Gli attachment in whitelist hanno un badge visivo distinto e non sono selezionabili per eliminazione.
- Il log eliminazioni è accessibile in un tab separato.

---

## Casi Edge Critici

### Immagini referenziate da plugin terzi
Alcuni plugin (slider, page builder) salvano riferimenti alle immagini in meta custom o shortcode. La scansione base NON intercetta questi casi. La whitelist è la difesa per questi casi — se un'immagine è usata da un plugin terzo, va aggiunta manualmente alla whitelist.

### Attachment usati come documenti
PDF, file zip, ecc. sono attachment WP ma non "immagini". Il plugin li include nella scansione ma dovrebbero essere filtrabili per mime type.

### File fisico mancante
Può esistere un attachment nel DB senza il file fisico corrispondente sul disco (upload corrotto, migrazione parziale). `rp_mc_estimate_orphan_size()` deve gestire questo caso senza crashare.

### Reverse lookup URL → ID
`attachment_url_to_postid()` è costosa. Per librerie grandi, costruire un index URL→ID una volta sola e cacharlo per la sessione della scansione.

---

## File di Riferimento

| File | Contenuto |
|---|---|
| `docs/ARCHITECTURE.md` | Flusso scansione, stato UI, casi edge |
| `docs/ROADMAP.md` | Feature built + backlog |
