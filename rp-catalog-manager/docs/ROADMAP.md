# ROADMAP — RP Catalog Manager

## Stato Attuale: v0.1 (da costruire)

Plugin non ancora iniziato. Read-only: zero rischio sul DB.

---

## 🔴 P0 — MVP

### Reader
- [ ] `rp_cm_get_all_products()` con WC_Product_Query + filtri base
- [ ] `rp_cm_get_product_variants()` — varianti ordinate per taglia
- [ ] `rp_cm_get_product_images()` — featured + gallery URL

### Aggregator
- [ ] `rp_cm_extract_sizes()` — range, available, in_stock, out_of_stock
- [ ] `rp_cm_calculate_pricing()` — min/max/avg regular + sale
- [ ] `rp_cm_calculate_stock_status()` — full/partial/out/unmanaged
- [ ] `rp_cm_aggregate_product()` — assembla catalog_entry

### Tree Builder
- [ ] `rp_cm_get_product_tree_path()` — risolve gerarchia categorie
- [ ] `rp_cm_build_tree()` — organizza flat array in struttura annidata

### Exporter
- [ ] `rp_cm_export_catalog()` — formato CATALOG completo
- [ ] `rp_cm_export_full()` — formato FULL EXPORT completo
- [ ] `rp_cm_build_summary()` — calcola blocco summary dal tree

### AJAX
- [ ] `rp_cm_ajax_export_catalog`
- [ ] `rp_cm_ajax_export_full`
- [ ] `rp_cm_ajax_get_summary` — solo summary, senza tree (per overview rapida)
- [ ] `rp_cm_ajax_get_tree_paths` — lista brand/sezioni disponibili per filtri UI

### Admin UI — MVP
- [ ] Menu WP Admin "RP Catalog"
- [ ] **Tab Overview:** summary card con totali (prodotti, varianti, in stock, brand)
- [ ] **Tab Catalog:** filtri (status, brand, in_stock) + bottone "Genera" + JSON viewer + bottone Download
- [ ] **Tab Full Export:** stessi filtri + bottone "Genera Full" + JSON viewer + bottone Download
- [ ] JSON viewer con syntax highlighting + collasso nodi
- [ ] Bottone "Copia JSON" → clipboard
- [ ] Bottone "Download .json" → file download diretto nel browser
- [ ] Dark theme, JetBrains Mono + DM Sans

---

## 🟡 P1 — Feature Post-MVP

### SEO Audit Mode
Aggiunge al catalog entry un campo `seo_score` calcolato:
- [ ] Controlla: has focus_keyword, has meta_title, has meta_description, has_description, has_short_description, has_featured_image
- [ ] Score 0-6 per ogni prodotto
- [ ] Tab "SEO Audit" nell'UI: lista prodotti con score basso, filtrabili per brand
- [ ] Utile per identificare prodotti che necessitano attenzione SEO

### Catalog Diff
Confronta due export JSON e mostra le differenze:
- [ ] Prodotti aggiunti / rimossi
- [ ] Varianti cambiate (prezzo, stock)
- [ ] Utile per "cosa è cambiato questa settimana?"
- [ ] Input: due file JSON (upload) oppure confronto con un export salvato in wp_options

### Export schedulato
- [ ] WP Cron: genera automaticamente il catalog ogni notte
- [ ] Salva in wp_options (o file su disco) come "ultimo export noto"
- [ ] Usato come baseline per il Catalog Diff

### Filtri avanzati UI
- [ ] Filtra per stock_status: full / partial / out
- [ ] Filtra per seo_complete: true/false
- [ ] Filtra per date_modified (es. "modificati questa settimana")
- [ ] Filtra per range prezzo

---

## 🟢 P2 — Futuro

- [ ] **Export CSV** — versione piatta del catalog (una riga per prodotto) per uso in Excel/Sheets
- [ ] **Export per LLM** — formato ottimizzato per input a ChatGPT/Claude: ogni prodotto come testo strutturato pronto per "genera descrizione SEO per questi prodotti"
- [ ] **Catalog share link** — genera un URL temporaneo (con nonce) per condividere il catalog JSON con il titolare senza accesso WP Admin
- [ ] **WP-CLI** — `wp rp-catalog export`, `wp rp-catalog export --mode=full --brand=Nike --output=catalog.json`
- [ ] **Webhook** — POST del catalog a un URL esterno on-demand o schedulato (per integrazioni)
- [ ] **Inventory gap analysis** — rileva taglie "mancanti" (es. brand ha 40-46 ma manca il 43 per questo modello)
- [ ] **Price consistency check** — rileva varianti con prezzo anomalo rispetto alla media del prodotto (outlier detection semplice)

---

## Note Architetturali per il Futuro

### Perché il FULL EXPORT è separato dal CATALOG e non un'estensione

Il FULL EXPORT include HTML grezzo (le descrizioni) e URL delle immagini. Per 200 prodotti questo porta l'output a ~500KB+. Tenere i due formati separati permette di usare il CATALOG (leggero, ~50KB) come dato di lavoro quotidiano senza mai generare il FULL inutilmente.

In futuro, il FULL EXPORT potrebbe essere generato in background (WP Cron) e cachato — non ha senso rigenerarlo ad ogni click se i dati non sono cambiati.

### Relazione con rp-product-manager e rp-rest-caller

Il Catalog Manager è **read-only puro** — non dipende da rp-product-manager.
In futuro, rp-rest-caller potrebbe usare il formato CATALOG come schema di confronto:
"Feed fornitore → mappa → confronta con catalog locale → mostra gap → importa mancanti"
Questo è il flusso completo di un sistema di inventory management. Il Catalog Manager ne è il fondamento.
