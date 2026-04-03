# CONVENTIONS.md — ResellPiacenza Plugins

> Questo file è la source of truth per tutto ciò che è condiviso tra i plugin.
> Ogni `CLAUDE.md` di plugin lo referenzia. In caso di conflitto, vince questo file.

---

## Struttura del Monorepo

```
resellpiacenza-plugins/
├── CONVENTIONS.md               ← questo file
├── rp-product-manager/          ← CRUD layer + Admin UI prodotti
├── rp-media-cleaner/            ← Scanner orfani + whitelist
├── rp-rest-caller/              ← HTTP client + feed importer
├── rp-catalog-manager/          ← Export catalogo JSON (read-only)
└── rp-email-marketing/          ← Email marketing: test, campagne, scheduling
```

Ogni plugin è **deployato e attivato indipendentemente** su WordPress. Un plugin non richiede che un altro sia presente, salvo dipendenze opzionali esplicite (vedi sezione Dipendenze).

---

## Dipendenze Cross-Plugin

| Plugin | Dipende da | Tipo | Comportamento se assente |
|---|---|---|---|
| `rp-rest-caller` | `rp-product-manager` | Opzionale | Tab "Import" nascosto, messaggio esplicativo |
| `rp-catalog-manager` | — | Nessuna | Standalone completo |
| `rp-media-cleaner` | — | Nessuna | Standalone completo |
| `rp-email-marketing` | — | Nessuna | Standalone (Hustle opzionale per contatti) |

**Regola:** le dipendenze opzionali si verificano con `function_exists()`, mai con `is_plugin_active()`. Questo rende i plugin indipendenti dall'ordine di attivazione.

```php
// CORRETTO
if ( function_exists( 'rp_create_product' ) ) { ... }

// SBAGLIATO
if ( is_plugin_active( 'rp-product-manager/rp-product-manager.php' ) ) { ... }
```

---

## Prefix delle Funzioni PHP

Ogni plugin ha un prefix univoco per evitare collisioni nel namespace globale PHP:

| Plugin | Prefix funzioni | Prefix AJAX actions | Prefix nonce | Prefix wp_options |
|---|---|---|---|---|
| `rp-product-manager` | `rp_` | `rp_ajax_*` | `rp_crud_nonce` | `rp_*` |
| `rp-media-cleaner` | `rp_mc_` | `rp_mc_ajax_*` | `rp_mc_nonce` | `rp_mc_*` |
| `rp-rest-caller` | `rp_rc_` | `rp_rc_ajax_*` | `rp_rc_nonce` | `rp_rc_*` |
| `rp-catalog-manager` | `rp_cm_` | `rp_cm_ajax_*` | `rp_cm_nonce` | `rp_cm_*` |
| `rp-email-marketing` | `rp_em_` | `rp_em_ajax_*` | `rp_em_nonce` | `rp_em_*` |

---

## Struttura Interna di Ogni Plugin

Tutti i plugin seguono lo stesso schema:

```
rp-{nome}/
├── rp-{nome}.php        ← Entry point. SOLO require_once dei moduli. Zero logica.
└── includes/
    ├── *.php            ← Moduli con responsabilità singola (vedi CLAUDE.md del plugin)
    ├── ajax.php         ← TUTTI i wp_ajax_* handler. Solo glue code.
    └── admin-page.php   ← add_menu_page() + render HTML/CSS/JS.
```

**Regola di layer universale:**
- I file di logica (non `ajax.php`, non `admin-page.php`) non contengono hook WordPress.
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

---

## JavaScript — Regole Condivise

- **Vanilla JS puro.** Nessun framework, nessun bundler, nessun npm.
- **Pattern module IIFE** con API pubblica esplicita:
  ```javascript
  const RPM = (function(){
      // ... tutto privato
      return { metodoPublico1, metodoPublico2 };
  })();
  ```
- **Stato centralizzato** in oggetto `state = {}` — mai variabili globali sparse.
- **AJAX sempre via `fetch()` con `FormData`** — mai jQuery `$.ajax()`.
- **Nessun `console.log()` nel codice committato** — solo in development.

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
Ogni pagina admin usa questo layout base:
```
┌─────────────────────────────────────────┐
│  Header bar (logo plugin + titolo)      │
├──────────┬──────────────────────────────┤
│  Sidebar │  Content area               │
│  (tabs)  │  (panel attivo)             │
│          │                             │
└──────────┴──────────────────────────────┘
```
Il root div occupa `100vh` con `margin: -10px -20px -20px -20px` per annullare il padding di WP Admin.

---

## Voce nel Menu WP Admin

Ogni plugin aggiunge una voce al menu principale (non sotto-menu):

| Plugin | Label menu | Dashicon | Posizione |
|---|---|---|---|
| `rp-product-manager` | RP Products | `dashicons-sneakers` | 58 |
| `rp-media-cleaner` | RP Media | `dashicons-images-alt2` | 59 |
| `rp-rest-caller` | RP REST | `dashicons-rest-api` | 60 |
| `rp-catalog-manager` | RP Catalog | `dashicons-category` | 61 |
| `rp-email-marketing` | RP Email | `dashicons-email-alt` | 62 |

---

## Regex Condivisa: Attributo Taglia

Usata da `rp-product-manager` e `rp-catalog-manager` per identificare l'attributo taglia nelle varianti WooCommerce:

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
