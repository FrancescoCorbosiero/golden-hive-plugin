# CONVENTIONS.md — Golden Hive Plugins

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
│       ├── product/             ← CRUD + varianti (da rp-product-manager)
│       ├── core/                ← Product factory condiviso
│       ├── catalog/             ← Catalogo, tassonomia, export/import
│       ├── media/               ← Scanner, libreria, orfani, whitelist
│       ├── feeds/               ← HTTP client, feed GoldenSneakers
│       ├── filter/              ← Query engine composabile + condizioni
│       ├── bulk/                ← Azioni bulk + ordinamento programmatico
│       ├── email/               ← Contatti, mailer, campagne (da rp-email-marketing)
│       ├── views/               ← CSS/JS asset per la UI admin
│       └── admin-page.php       ← UI unificata con sidebar a tab
├── rp-product-manager/          ← Standalone: CRUD prodotti (mergiato in golden-hive)
├── rp-media-cleaner/            ← Standalone: scanner orfani + whitelist
├── rp-rest-caller/              ← Standalone: HTTP client + feed importer
├── rp-catalog-manager/          ← Standalone: export catalogo JSON
└── rp-email-marketing/          ← Standalone: email marketing (mergiato in golden-hive)
```

**Golden Hive** è il plugin principale. Contiene tutti i moduli in un'unica UI unificata.
I plugin `rp-*` standalone rimangono per deployment indipendente — ma le funzionalità core (product, email) sono mergiate in golden-hive.

Ogni plugin è **deployato e attivato indipendentemente** su WordPress. Quando golden-hive e un plugin standalone sono entrambi attivi, le guard `function_exists()` / `defined()` prevengono il double-loading.

---

## Dipendenze Cross-Plugin

| Plugin | Dipende da | Tipo | Comportamento se assente |
|---|---|---|---|
| `golden-hive` | — | Nessuna | Standalone completo con tutti i moduli |
| `rp-rest-caller` | `rp-product-manager` | Opzionale | Tab "Import" nascosto, messaggio esplicativo |
| `rp-catalog-manager` | — | Nessuna | Standalone completo |
| `rp-media-cleaner` | — | Nessuna | Standalone completo |
| `rp-email-marketing` | — | Nessuna | Standalone (Hustle opzionale per contatti) |

**Regola:** le dipendenze opzionali si verificano con `function_exists()`, mai con `is_plugin_active()`. Questo rende i plugin indipendenti dall'ordine di attivazione.

**Co-esistenza:** quando golden-hive e un plugin standalone condividono gli stessi file (product, email), ogni file ha una guard all'inizio:
```php
// Prevent double-loading
if ( function_exists( 'rp_get_product' ) ) return;
```

---

## Prefix delle Funzioni PHP

Ogni plugin/modulo ha un prefix univoco per evitare collisioni nel namespace globale PHP:

| Plugin / Modulo | Prefix funzioni | Prefix AJAX actions | Prefix nonce | Prefix wp_options |
|---|---|---|---|---|
| `golden-hive` (filter/bulk) | `gh_` | `gh_ajax_*` | `gh_nonce` | `gh_*` |
| `golden-hive` (product) | `rp_` | `rp_ajax_*` | `rp_crud_nonce` | `rp_*` |
| `golden-hive` (catalog) | `rp_cm_` | `rp_cm_ajax_*` | `gh_nonce` | `rp_cm_*` |
| `golden-hive` (email) | `rp_em_` | `rp_em_ajax_*` | `rp_em_nonce` | `rp_em_*` |
| `rp-product-manager` | `rp_` | `rp_ajax_*` | `rp_crud_nonce` | `rp_*` |
| `rp-media-cleaner` | `rp_mc_` | `rp_mc_ajax_*` | `rp_mc_nonce` | `rp_mc_*` |
| `rp-rest-caller` | `rp_rc_` | `rp_rc_ajax_*` | `rp_rc_nonce` | `rp_rc_*` |
| `rp-catalog-manager` | `rp_cm_` | `rp_cm_ajax_*` | `rp_cm_nonce` | `rp_cm_*` |
| `rp-email-marketing` | `rp_em_` | `rp_em_ajax_*` | `rp_em_nonce` | `rp_em_*` |

**Nota:** i moduli mergiati in golden-hive mantengono il prefix originale per compatibilità. I moduli nuovi (filter, bulk) usano il prefix `gh_`.

---

## Struttura Interna — Plugin Standalone

Tutti i plugin standalone seguono lo stesso schema:

```
rp-{nome}/
├── rp-{nome}.php        ← Entry point. SOLO require_once dei moduli. Zero logica.
└── includes/
    ├── *.php            ← Moduli con responsabilità singola (vedi CLAUDE.md del plugin)
    ├── ajax.php         ← TUTTI i wp_ajax_* handler. Solo glue code.
    └── admin-page.php   ← add_menu_page() + render HTML/CSS/JS.
```

## Struttura Interna — Golden Hive

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
}
```

### Scope CSS
Ogni plugin scopla i suoi stili sotto il suo ID root per non interferire con WP Admin:
```css
#gh    { ... }   /* golden-hive (plugin principale) */
#rpm   { ... }   /* rp-product-manager */
#rpmc  { ... }   /* rp-media-cleaner */
#rprc  { ... }   /* rp-rest-caller */
#rpcm  { ... }   /* rp-catalog-manager */
#rpem  { ... }   /* rp-email-marketing */
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
Golden Hive usa un layout sidebar + content:
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
| `golden-hive` | Golden Hive | `dashicons-screenoptions` | 57 |
| `rp-product-manager` | RP Products | `dashicons-sneakers` | 58 |
| `rp-media-cleaner` | RP Media | `dashicons-images-alt2` | 59 |
| `rp-rest-caller` | RP REST | `dashicons-rest-api` | 60 |
| `rp-catalog-manager` | RP Catalog | `dashicons-category` | 61 |
| `rp-email-marketing` | RP Email | `dashicons-email-alt` | 62 |

---

## Regex Condivisa: Attributo Taglia

Usata da `rp-product-manager`, `rp-catalog-manager` e `golden-hive` per identificare l'attributo taglia nelle varianti WooCommerce:

```php
const RP_SIZE_ATTRIBUTE_REGEX = '/(taglia|size|misura|eu|uk|us|fr|cm)/i';
```

Se il negozio cambia il nome dell'attributo taglia, questa regex va aggiornata in tutti i plugin che la usano.

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
