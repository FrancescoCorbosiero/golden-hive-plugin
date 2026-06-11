# CONVENTIONS.md — Hive Commerce Plugins

> Questo file è la source of truth per tutto ciò che è condiviso tra i plugin.
> Ogni `CLAUDE.md` di plugin lo referenzia. In caso di conflitto, vince questo file.

---

## Struttura del Monorepo

```
golden-hive-plugin/
├── CONVENTIONS.md               ← questo file
├── golden-hive/                 ← PLUGIN PRINCIPALE: suite unificata
│   ├── golden-hive.php          ← Entry point
│   └── includes/
│       ├── product/             ← CRUD + varianti
│       ├── core/                ← Product factory condiviso
│       ├── catalog/             ← Catalogo, tassonomia, export/import
│       ├── media/               ← Scanner, libreria, orfani, whitelist
│       ├── feeds/               ← HTTP client, feed GoldenSneakers
│       ├── filter/              ← Query engine composabile + condizioni
│       ├── bulk/                ← Azioni bulk + ordinamento programmatico
│       ├── email/               ← Contatti, mailer, campagne
│       ├── views/               ← CSS/JS asset per la UI admin
│       └── admin-page.php       ← UI unificata con sidebar a tab
└── hive-sync/                   ← Sync orchestrator (non-WP)
```

**Hive Commerce** è il plugin principale e unico. Contiene tutti i moduli in un'unica UI unificata.
I vecchi plugin standalone `rp-*` (product-manager, media-cleaner, rest-caller, catalog-manager, email-marketing, media-manager) sono stati **rimossi dal monorepo**: tutte le loro funzionalità sono mergiate in golden-hive. I moduli mergiati mantengono i prefix funzione originali (`rp_*`) per compatibilità con i dati esistenti.

### Rebrand "Golden Hive" → "Hive Commerce" — cosa NON è stato rinominato

Il rebrand copre il nome visibile (plugin header, menu admin, UI, email,
docs, commenti). Restano deliberatamente col nome legacy perché sono
**identificatori persistiti o contratti runtime** — rinominarli romperebbe
un'installazione live senza una migration:

- la directory del plugin `golden-hive/` e l'entry point `golden-hive.php`
  (identità del plugin per WordPress: rinominarli lo disattiva all'update)
- i prefix funzione/option/AJAX/nonce (`gh_*`, `rp_*`, `rp_cm_*`, `rp_em_*`, ...)
- le option keys, i transient, i cron hook e i post meta (`_gh_*`)
- il namespace PHP `GH\` (coerente con il prefix `gh_` mantenuto)
- il path uploads `wp-content/uploads/golden-hive/csv/` (referenziato dai
  feed config salvati in wp_options)
- il package composer `golden-hive/plugin` (legato alla directory)

Lo slug del menu admin invece è stato rinominato (`admin.php?page=hive-commerce`):
non è persistito, cambia solo l'URL.

---

## Prefix delle Funzioni PHP

Ogni plugin/modulo ha un prefix univoco per evitare collisioni nel namespace globale PHP:

| Plugin / Modulo | Prefix funzioni | Prefix AJAX actions | Prefix nonce | Prefix wp_options |
|---|---|---|---|---|
| `golden-hive` (filter/bulk) | `gh_` | `gh_ajax_*` | `gh_nonce` | `gh_*` |
| `golden-hive` (product) | `rp_` | `rp_ajax_*` | `rp_crud_nonce` | `rp_*` |
| `golden-hive` (catalog) | `rp_cm_` | `rp_cm_ajax_*` | `gh_nonce` | `rp_cm_*` |
| `golden-hive` (email) | `rp_em_` | `rp_em_ajax_*` | `rp_em_nonce` | `rp_em_*` |
| `golden-hive` (media) | `rp_mc_` | `rp_mc_ajax_*` | `rp_mc_nonce` | `rp_mc_*` |
| `golden-hive` (feeds) | `rp_rc_` | `rp_rc_ajax_*` | `rp_rc_nonce` | `rp_rc_*` |

**Nota:** i moduli mergiati dagli ex plugin standalone mantengono il prefix originale per compatibilità con i dati persistiti. I moduli nuovi (filter, bulk) usano il prefix `gh_`.

---

## Struttura Interna — Hive Commerce

```
golden-hive/
├── golden-hive.php          ← Entry point. Require di tutti i moduli.
└── includes/
    ├── product/             ← crud.php, variations.php
    ├── core/                ← product-factory.php
    ├── catalog/             ← reader, aggregator, tree-builder, exporter, importer, taxonomy-manager, bulk-creator, ajax
    ├── media/               ← scanner, library, whitelist, cleaner, ajax
    ├── feeds/               ← http-client, response-parser, saved-endpoints, feed-goldensneakers, ajax
    ├── filter/              ← conditions.php, query-engine.php, ajax.php
    ├── bulk/                ← actions.php, sorter.php, ajax.php
    ├── email/               ← contacts.php, mailer.php, campaigns.php, ajax.php
    ├── views/               ← css.php, panels.php, panels-operations.php, js.php, js2.php, js-operations.php
    └── admin-page.php       ← UI unificata con sidebar e tab
```

**Regola di layer universale:**
- I file di logica (non `ajax.php`, non `admin-page.php`) non contengono hook WordPress (eccezione: cron handler in campaigns.php).
- `ajax.php` non contiene logica business — solo sanitize, call, json response.
- `admin-page.php` non sa come funziona WooCommerce — solo UI.

---

## Sicurezza AJAX — Pattern Obbligatorio

Ogni handler AJAX deve iniziare esattamente così, senza eccezioni:

```php
add_action( 'wp_ajax_{prefix}_ajax_{action}', function () {
    check_ajax_referer( '{prefix}_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    // ... logica
    wp_send_json_success( $data );
    // oppure
    wp_send_json_error( 'Messaggio errore' );
} );
```

- Sempre `check_ajax_referer` prima di qualsiasi operazione.
- Sempre `current_user_can('manage_woocommerce')` — mai `is_admin()` da solo.
- Sempre `wp_send_json_success/error` — mai `echo` raw.
- Mai `wp_ajax_nopriv_*` — tutti i plugin sono solo per utenti autenticati.

### Helper canonici per nuovi handler (golden-hive)

Dal Batch 1 del refactor audit-driven, golden-hive espone wrapper che
collassano il boilerplate sopra. Il pattern sopra resta valido per
compatibilità (plugin standalone e codice esistente). I nuovi handler
di golden-hive dovrebbero usare:

```php
add_action( 'wp_ajax_gh_ajax_my_action', function () {
    gh_ajax_guard();                              // nonce (gh_ OR rp_em_) + cap
    $id    = gh_ajax_text( 'id' );
    $ids   = gh_ajax_int_array( 'product_ids' );
    $data  = gh_ajax_json( 'payload' );
    // ...
    wp_send_json_success( [...] );
} );
```

Disponibili anche: `gh_ajax_textarea`, `gh_ajax_key`, `gh_ajax_email`,
`gh_ajax_int`, `gh_ajax_bool`. Vedi `golden-hive/includes/core/ajax-helpers.php`.

---

## PHP — Regole Condivise

- **Versione minima:** PHP 8.0. Usare liberamente: named arguments, union types `int|string`, null-safe operator `?->`, match expression, `array_is_list()`.
- **Nessuna dipendenza Composer.** Zero. I plugin devono installarsi come zip senza toolchain.
- **`array_key_exists()` per update selettivi**, non `isset()` — permette di passare `null` o `''` per cancellare un campo.
- **Docblock su ogni funzione pubblica** con: descrizione, @param, @return, esempio d'uso.
- **Nessun `var_dump()` o `error_log()` nel codice committato** salvo dietro flag `WP_DEBUG`.
- **Double-load guard** su file condivisi tra golden-hive e plugin standalone.

---

## JavaScript — Regole Condivise

- **Vanilla JS puro.** Nessun framework, nessun bundler, nessun npm.
- **Pattern module IIFE** con API pubblica esplicita:
  ```javascript
  const GH = (function(){
      // ... tutto privato
      return { ajax, toast, switchTab, metodoPublico1 };
  })();
  ```
- **Stato centralizzato** in oggetto `state = {}` — mai variabili globali sparse.
- **AJAX sempre via `fetch()` con `FormData`** — mai jQuery `$.ajax()`.
- **Nessun `console.log()` nel codice committato** — solo in development.
- **Moduli aggiuntivi** (js-operations.php) estendono `GH` aggiungendo metodi dall'esterno.

---

## UI / CSS — Design System Condiviso

Tutti i plugin condividono lo stesso design system. L'utente deve sentire che sono un unico prodotto.

### Font Stack
```html
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
```
- **Monospace:** JetBrains Mono — codice, label, badge, ID numerici, valori tecnici
- **Sans:** DM Sans — testo normale, descrizioni, titoli UI

### Palette Colori (CSS Custom Properties)
```css
:root {
    --bg:  #0c0d10;   /* background principale */
    --s1:  #111317;   /* surface livello 1 (header, sidebar) */
    --s2:  #16181d;   /* surface livello 2 (card, toolbar) */
    --s3:  #1c1f26;   /* surface livello 3 (input, hover) */
    --b1:  #232630;   /* border standard */
    --b2:  #2e3240;   /* border highlight */
    --acc: #3d7fff;   /* accent principale (blu) — azioni primarie */
    --grn: #22c78b;   /* verde — successo, instock, ok */
    --red: #e85d5d;   /* rosso — errore, eliminazione, outofstock */
    --amb: #e8a824;   /* ambra — warning, modifiche non salvate, draft */
    --pur: #9b72f5;   /* viola — tipo variable, label speciali */
    --txt: #d8dce8;   /* testo principale */
    --dim: #5f6480;   /* testo secondario, label */
    --mut: #2a2d3a;   /* testo disabilitato, placeholder */

    /* Alpha variants per background muted di chip/badge/hover.
       Da usare invece di rgba(...) literal per mantenere coerenza. */
    --acc-10, --acc-15, --acc-30;
    --grn-10, --grn-15, --grn-30;
    --red-10, --red-15, --red-30;
    --amb-15, --amb-30;
    --pur-15, --pur-30;
}
```

### Scope CSS
Il plugin scopla i suoi stili sotto il suo ID root per non interferire con WP Admin:
```css
#gh { ... }   /* golden-hive (plugin principale) */
```

### Componenti Riutilizzabili

**Toast notification** — stesso pattern in tutti i plugin:
```javascript
function toast(msg, type = 'ok', ms = 3000) {
    const wrap = document.getElementById('{plugin}-toasts');
    const t = document.createElement('div');
    t.className = 'toast ' + type;  // 'ok' | 'err' | 'inf'
    t.textContent = msg;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), ms);
}
```
CSS colori: `.ok` → `--grn`, `.err` → `--red`, `.inf` → `--acc`

**Spinner inline:**
```html
<span class="spin"></span>
```
```css
.spin {
    display: inline-block; width: 9px; height: 9px;
    border: 1.5px solid var(--b2); border-top-color: var(--acc);
    border-radius: 50%; animation: sp .5s linear infinite;
}
@keyframes sp { to { transform: rotate(360deg); } }
```

**Card unificata (`.gh-card`)** — base per liste di entita cliccabili:
```html
<div class="gh-card gh-card--clickable" onclick="...">content</div>
<div class="gh-card gh-card--compact">content</div>
```
```css
.gh-card { background: var(--s2); border: 1px solid var(--b1); border-radius: 6px; padding: 12px; }
.gh-card--clickable:hover { border-color: var(--acc); }
```
Sostituisce gradualmente `.rpem-tpl-card`, `.rpem-camp-card`, `.gh-job-card` & co.

**Status chip unificato (`.gh-status`)** — 5 varianti con stesso visual language:
```html
<span class="gh-status gh-status--ok">Sent</span>
<span class="gh-status gh-status--err">Failed</span>
<span class="gh-status gh-status--warn">Pending</span>
<span class="gh-status gh-status--info">Scheduled</span>
<span class="gh-status gh-status--dim">Draft</span>
```
Pattern: background 15% alpha del colore target + text del colore pieno + border 30% alpha. Le classi legacy `.em-st-*` / `.st-*` sono repaintate con lo stesso visual (nessun rename HTML richiesto).

**Syntax highlight JSON** — stessa funzione `hl()` in tutti i plugin:
```javascript
function hl(json) {
    return String(json)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, m => {
            let c = 'jn';
            if (/^"/.test(m)) c = /:$/.test(m) ? 'jk' : 'js';
            else if (/true|false/.test(m)) c = 'jb';
            else if (/null/.test(m)) c = 'jx';
            return `<span class="${c}">${m}</span>`;
        });
}
```
CSS classi: `.jk` → `#a78bfa` (chiavi), `.js` → `--grn` (stringhe), `.jn` → `--amb` (numeri), `.jb` → `--acc` (boolean), `.jx` → `--red` (null)

### Layout Standard
Hive Commerce usa un layout sidebar + content:
```
┌─────────────────────────────────────────┐
│  Header bar (logo + titolo)             │
├──────────┬──────────────────────────────┤
│ CATALOGO │  Content area               │
│ Overview │  (panel attivo)             │
│ Catalog  │                             │
│ Taxonomy │                             │
│ OPERAZ.  │  ← Filtra & Agisci         │
│ Filtra   │  ← Ordinamento             │
│ Ordina   │                             │
│ MEDIA    │                             │
│ IMPORT   │                             │
│ TOOLS    │                             │
└──────────┴──────────────────────────────┘
```
Il root div occupa `100vh` con `margin: -10px -20px -20px -20px` per annullare il padding di WP Admin.

---

## Voce nel Menu WP Admin

| Plugin | Label menu | Dashicon | Posizione |
|---|---|---|---|
| `golden-hive` | Hive Commerce | `dashicons-screenoptions` | 57 |

---

## Regex Condivisa: Attributo Taglia

Usata da `golden-hive` per identificare l'attributo taglia nelle varianti WooCommerce:

```php
const RP_SIZE_ATTRIBUTE_REGEX = '/(taglia|size|misura|eu|uk|us|fr|cm)/i';
```

Se il negozio cambia il nome dell'attributo taglia, questa regex va aggiornata ovunque sia usata.

---

## Prompt Bootstrap per Claude Code (uguale per tutti i plugin)

```
Leggi CLAUDE.md nella root di questo plugin prima di qualsiasi altra cosa.
Poi leggi ../CONVENTIONS.md per le convenzioni condivise del monorepo.
Poi leggi docs/ARCHITECTURE.md e docs/ROADMAP.md.

Fatto questo, dimmi:
1. Hai tutto il contesto necessario per lavorare su questo plugin?
2. Ci sono file che non riesci a trovare o che dovrei aggiungere al repo?
3. Qual è la tua lettura del prossimo task prioritario dalla roadmap?

Non iniziare nessun task finché non abbiamo confermato insieme il contesto.
```
