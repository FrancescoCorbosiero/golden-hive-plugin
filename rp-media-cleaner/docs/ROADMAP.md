# ROADMAP — RP Media Cleaner

## Stato Attuale: v0.1 (da costruire)

Plugin non ancora iniziato. Questo documento definisce scope e priorità.

---

## 🔴 P0 — MVP (primo sprint)

### Core Scanner
- [ ] `rp_mc_get_all_attachments()` — lista completa attachment con filesize
- [ ] `rp_mc_get_used_attachment_ids()` — raccolta da: featured image prodotti, gallery prodotti, varianti WooCommerce, featured image post/pagine, content inline
- [ ] `rp_mc_get_orphan_attachments()` — diff + esclusione whitelist
- [ ] `rp_mc_estimate_orphan_size()` — calcolo spazio recuperabile

### Whitelist Core
- [ ] `rp_mc_get_whitelist()` / `rp_mc_add_to_whitelist()` / `rp_mc_remove_from_whitelist()`
- [ ] `rp_mc_is_whitelisted()` — check per ID e per URL
- [ ] Persistenza in `wp_options` come JSON

### Cleaner Core
- [ ] `rp_mc_delete_attachment()` — singola eliminazione con whitelist check obbligatorio
- [ ] `rp_mc_bulk_delete()` — bulk con report dettagliato
- [ ] `rp_mc_get_deletion_log()` — log FIFO ultimi 500 eventi

### AJAX Layer
- [ ] Tutti gli endpoint listati in CLAUDE.md

### Admin UI — MVP
- [ ] Menu WP Admin "RP Media"
- [ ] Tab "Scansione": bottone scan, tabella orfani con thumbnail preview, checkbox selezione, bulk delete con doppio confirm
- [ ] Tab "Whitelist": lista entries con reason, add/remove
- [ ] Tab "Log": tabella ultime eliminazioni
- [ ] Toast notifications (stile coerente con rp-product-manager)
- [ ] Dark theme, font JetBrains Mono + DM Sans

---

## 🟡 P1 — Miglioramenti post-MVP

### Scansione paginata (librerie grandi)
- [ ] Endpoint `rp_mc_ajax_scan_chunk` con session_id e offset
- [ ] Progress bar nell'UI
- [ ] Transient WP per stato intermedio scansione
- [ ] Timeout safe: chunk da 200 attachment per chiamata

### Filtri UI
- [ ] Filtra orfani per: mime type (immagini / video / documenti / tutti)
- [ ] Filtra per dimensione minima (es. "mostra solo file >100KB")
- [ ] Filtra per data upload (es. "più vecchi di 6 mesi")
- [ ] Ordina per: dimensione, data, nome

### Whitelist import/export
- [ ] Export whitelist come JSON
- [ ] Import whitelist da JSON (utile per migrazioni)
- [ ] Auto-populate whitelist con i loghi del tema (rilevati da `get_theme_mods()`)

### Dry Run mode
- [ ] Toggle "modalità simulazione": mostra cosa verrebbe eliminato senza eliminare
- [ ] Report scaricabile in CSV dei potenziali orfani

---

## 🟢 P2 — Futuro

- [ ] **Scheduled scan** — scansione automatica settimanale con report email all'admin
- [ ] **Duplicate finder** — trova immagini con stesso hash MD5 (duplicati esatti)
- [ ] **WP-CLI** — `wp rp-media scan`, `wp rp-media clean --dry-run`, `wp rp-media whitelist add <id>`
- [ ] **Backup before delete** — opzione per spostare file in cartella archivio prima di eliminare
- [ ] **Plugin terzi detection** — scanner specifico per ACF, Elementor, WPBakery (leggono le loro tabelle custom)
- [ ] **Size breakdown** — grafico distribuzione dimensioni (quanti MB per categoria: prodotti, post, orfani)

---

## Decisioni da Prendere prima di Iniziare

| Domanda | Opzioni | Raccomandazione |
|---|---|---|
| Scansione content inline? | Regex su post_content (lento ma completo) vs. skip | Includere, ma con cache |
| Cosa fare con i video? | Includi / escludi da default | Includi con filtro visibile |
| Mime types da scansionare | Solo immagini / tutti gli attachment | Tutti, filtrabili |
| Limit scan timeout | 30s / 60s / chunked | Chunked da P0 se libreria >500 |
