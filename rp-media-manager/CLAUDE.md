# CLAUDE.md — RP Media Manager

> Stai lavorando su **rp-media-manager**. La root del tuo lavoro e `/rp-media-manager/`.
> Questo plugin sostituisce e amplia `rp-media-cleaner`.

---

## Contesto del Plugin

**RP Media Manager** e il gestore media centralizzato per ResellPiacenza.
Gestisce il ciclo di vita degli attachment WordPress in relazione ai prodotti WooCommerce.

**Tre responsabilita principali:**
1. **Browse/Search** — navigare e cercare nella media library
2. **Product-Media Mapping** — vedere e gestire quali immagini sono associate a quali prodotti
3. **Orphan Cleanup** — trovare e eliminare in sicurezza le immagini non usate

**La whitelist resta il cuore della sicurezza**: nessun attachment in whitelist viene mai eliminato.

---

## Struttura

```
rp-media-manager/
├── rp-media-manager.php     ← Entry point
└── includes/
    ├── scanner.php          ← Trova tutti gli attachment, identifica orfani
    ├── library.php          ← Browse, search, product-media mapping, assegnazione
    ├── whitelist.php        ← CRUD whitelist (wp_options)
    ├── cleaner.php          ← Eliminazione sicura con whitelist check + log
    ├── ajax.php             ← Tutti gli handler AJAX (prefix: rp_mm_ajax_*)
    └── admin-page.php       ← UI admin con 4 tab
```

## Tab UI

| Tab | Funzione |
|-----|----------|
| **Mapping** | Tabella prodotto → immagini (featured + gallery) |
| **Browse** | Griglia media library con ricerca e info usage |
| **Orphans** | Scanner orfani con selezione bulk e eliminazione |
| **Whitelist** | Lista protezione con add/remove |

## Prefix: `rp_mm_`
## Nonce: `rp_mm_nonce`
## Menu position: 59, Dashicon: `dashicons-format-gallery`
