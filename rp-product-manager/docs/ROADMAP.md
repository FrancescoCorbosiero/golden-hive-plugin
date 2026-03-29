# ROADMAP — RP Product Manager

## Stato Attuale (v1.0.0)

### ✅ Completato

#### PHP Layer (crud.php + variations.php)
- [x] `rp_get_product()` — lettura completa prodotto + meta Rank Math
- [x] `rp_create_product()` — creazione prodotto simple con tutti i campi
- [x] `rp_update_product()` — update selettivo (solo campi presenti nel payload)
- [x] `rp_delete_product()` — soft delete (cestino) o hard delete (force)
- [x] `rp_search_products()` — ricerca multi-strategia: ID → SKU esatto → fulltext → SKU LIKE
- [x] `rp_get_product_variations()` — legge varianti, estrae taglia, ordina numericamente
- [x] `rp_update_variation()` — aggiorna singola variante + sync prodotto padre
- [x] `rp_bulk_update_variations()` — aggiorna N varianti in una chiamata

#### AJAX Layer (ajax.php)
- [x] `rp_ajax_read`
- [x] `rp_ajax_create`
- [x] `rp_ajax_update`
- [x] `rp_ajax_delete`
- [x] `rp_ajax_search`
- [x] `rp_ajax_get_variations`
- [x] `rp_ajax_save_variations`

#### Admin UI (admin-page.php)
- [x] Search bar con dropdown (ID / SKU / titolo, 280ms debounce, keyboard nav)
- [x] Product card (stato attivo, nome, SKU, prezzo, status badge)
- [x] Tab: Varianti — tabella inline editabile con dirty tracking visivo
- [x] Tab: Varianti — bulk toolbar (prezzo reg., saldo, stock, clear saldo)
- [x] Tab: Varianti — select/deselect righe + select all
- [x] Tab: Varianti — save con singola AJAX call + refresh automatico
- [x] Tab: Prodotto — form completo (nome, SKU, prezzi, status, stock, descrizione breve, Rank Math)
- [x] Tab: JSON — editor raw + viewer syntax-highlighted affiancati
- [x] Tab: JSON — POST Create / PUT Update / DELETE diretti dall'editor
- [x] Toast notifications (ok / err / inf)
- [x] Dark theme, mobile-aware

---

## Backlog Prioritizzato

### 🔴 P0 — Alta priorità / Prossimi sprint

#### Bulk Import da CSV
**Contesto:** Il catalogo esiste già in `catalogo.csv` (217 prodotti). Serve importare prodotti variabili con tutte le taglie senza inserirli uno a uno.

**Scope:**
- Nuova funzione `rp_import_product_from_row(array $csv_row): int|WP_Error`
- Gestisce tipo `variable` con creazione automatica varianti per taglia
- Mappa colonne CSV → campi WooCommerce
- AJAX endpoint `rp_ajax_bulk_import` con progress tracking
- UI: tab "Import" con file upload CSV + preview + progress bar

**File CSV attesi:**
```
Sezione, Marca, Sottocategoria, SKU, Titolo, Query, Taglie, Prezzo, Prezzo Scontato
```
Taglie formato: `40|40.5|41|42` (pipe-separated EU sizes)

---

#### Stock Management avanzato
**Contesto:** Il titolare vuole sapere subito quando una taglia va a zero.

**Scope:**
- `rp_get_low_stock_variations(int $threshold = 1): array` — lista varianti con stock ≤ threshold
- AJAX endpoint `rp_ajax_low_stock`
- UI: badge nel tab Varianti quando ci sono taglie esaurite
- (futuro) Email/notifica admin automatica

---

#### Ricerca prodotti migliorata
**Scope:**
- Filtrare per categoria nella search dropdown
- Filtrare per status (published / draft / all)
- Aggiungere risultati paginati (ora hardcoded a 8)

---

### 🟡 P1 — Media priorità

#### Image Helper
**Contesto:** Associare immagini ai prodotti è lento via UI WP.

**Scope:**
- `rp_set_product_image_from_url(int $product_id, string $url): int|WP_Error`
  — scarica immagine, la aggiunge alla media library, la imposta come featured
- `rp_set_product_gallery_from_urls(int $product_id, array $urls): array`
- AJAX endpoints + UI nel tab Prodotto

---

#### Duplicate Product
**Scope:**
- `rp_duplicate_product(int $product_id): int|WP_Error`
  — clona prodotto incluse varianti e meta, in stato draft
- Utile per prodotti con colorway diverso dello stesso modello

---

#### Price Batch Update
**Contesto:** Quando il titolare vuole applicare un markup percentuale a una categoria.

**Scope:**
- `rp_apply_price_multiplier(array $product_ids, float $multiplier): array`
- UI: input "moltiplica prezzi per X" nella bulk toolbar

---

### 🟢 P2 — Bassa priorità / Futuro

- [ ] **Export CSV** — esporta prodotti filtrati in formato catalogo
- [ ] **Variation attribute builder** — UI per creare/modificare attributi WC (attualmente solo via WP Admin)
- [ ] **SEO bulk** — applica meta title/description/keyword a N prodotti con template
- [ ] **Change log** — log delle modifiche fatte tramite il plugin (chi, cosa, quando)
- [ ] **WP-CLI commands** — `wp rp import`, `wp rp sync-stock`, ecc. per automazione server-side
- [ ] **Unit tests** — PHPUnit per le funzioni `rp_*`
- [ ] **REST API endpoints** — esporre le funzioni `rp_*` anche via REST (per integrazioni esterne)

---

## Decisioni Architetturali da Rivedere

| Decisione | Motivazione attuale | Da rivalutare quando |
|---|---|---|
| Nessun Composer | Zero complessità di setup | Si aggiungono librerie esterne |
| Vanilla JS (no build) | Deploy immediato, zero toolchain | UI diventa troppo complessa |
| Tutto in un plugin | Semplicità | Si separa logica da più plugin client |
| PHP 8.0 minimo | Sintassi moderna | Il VPS è su versione precedente |
