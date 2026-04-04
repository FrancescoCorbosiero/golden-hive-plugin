# CLAUDE.md — RP Email Marketing

> Stai lavorando su **rp-email-marketing**. La root del tuo lavoro è `/rp-email-marketing/`.
> Non toccare le altre cartelle del monorepo salvo indicazione esplicita.
>
> **NOTA:** I moduli backend (`contacts.php`, `mailer.php`, `campaigns.php`, `ajax.php`)
> sono stati mergiati in **golden-hive** (`/golden-hive/includes/email/`). Le due copie
> sono tenute in sync con guard `function_exists()` / `defined()` per la co-esistenza.
> L'`admin-page.php` con UI standalone esiste solo in questo plugin (golden-hive ha la sua UI integrata).
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin
> 3. `docs/ARCHITECTURE.md`

---

## Contesto del Plugin

**RP Email Marketing** è un plugin WordPress standalone per ResellPiacenza.

**Problema che risolve:** ResellPiacenza ha bisogno di inviare campagne email ai propri iscritti (raccolti tramite Hustle optin o CSV custom) senza dipendere da servizi esterni costosi. Il plugin sfrutta `wp_mail()` che viene instradato su **AWS SES** tramite **WP Mail SMTP** in modo completamente trasparente.

**Funzionalita principali:**
1. **Test Email** — invio email di test per verificare il routing wp_mail → SES
2. **Campagne** — creazione, salvataggio, preview e invio di campagne email
3. **Sorgenti contatti** — Hustle optin lists, CSV upload, merge multi-sorgente con deduplicazione
4. **Scheduling** — programmazione invio a datetime specifico via WP-Cron
5. **Rate limiting** — rispetta i limiti SES con pause configurabili tra invii

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| Email delivery | wp_mail() → WP Mail SMTP → AWS SES |
| Contact source | Hustle plugin (optin modules) + CSV raw |
| Scheduling | WP-Cron (wp_schedule_single_event) |
| PHP | 8.0+ |
| Admin UI | Vanilla JS + CSS custom — stesso stile del monorepo |
| Font stack UI | JetBrains Mono + DM Sans (Google Fonts) |

---

## Struttura del Plugin

```
rp-email-marketing/
├── rp-email-marketing.php   ← Entry point. Solo require_once dei moduli.
├── CLAUDE.md                ← Questo file.
├── docs/
│   └── ARCHITECTURE.md      ← Architettura dettagliata.
└── includes/
    ├── contacts.php         ← Sorgenti contatti: Hustle, CSV, merge/dedupe.
    ├── mailer.php           ← wp_mail wrapper, test email, personalizzazione.
    ├── campaigns.php        ← CRUD campagne, scheduling WP-Cron.
    ├── ajax.php             ← Tutti i wp_ajax_rp_em_* handler.
    └── admin-page.php       ← UI admin (tabs: Test, Campagne, Contatti).
```

### Regola fondamentale dei layer

```
contacts.php    →  "Chi" (sorgenti contatti, merge, deduplica)
mailer.php      →  "Invia" (wp_mail wrapper, personalizzazione, template)
campaigns.php   →  "Gestisci" (CRUD, scheduling, esecuzione)
ajax.php        →  "Bridge" (sanitize → chiama funzione → json response)
admin-page.php  →  "Mostra" (UI pura, zero logica business)
```

---

## Funzioni PHP Disponibili

### `contacts.php`

```php
rp_em_get_hustle_modules(): array
// Ritorna i moduli Hustle di tipo optin.
// [ { module_id, module_name, module_type } ]

rp_em_get_hustle_subscribers(array $module_ids = []): array
// Iscritti da Hustle. $module_ids vuoto = tutti i moduli.
// Supporta merge multi-modulo con deduplicazione automatica.
// [ { email, display_name, module_id, date_created } ]

rp_em_parse_csv_contacts(string $csv_content): array
// Parsa CSV raw. Auto-detect separatore e colonne.
// [ { email, display_name, source: 'csv' } ]

rp_em_parse_csv_file(string $file_path): array
// Parsa file CSV uploadato.

rp_em_merge_contacts(array ...$sources): array
// Mergia sorgenti multiple con deduplicazione cross-source.

rp_em_deduplicate_contacts(array $contacts): array
// Deduplica per email (case-insensitive), mantiene prima occorrenza.

rp_em_export_contacts_csv(array $contacts, string $filename = ''): void
// Esporta contatti in CSV con BOM UTF-8 per Excel.

rp_em_count_by_source(array $contacts): array
// [ 'hustle' => int, 'csv' => int, 'total' => int ]
```

### `mailer.php`

```php
rp_em_send_test_email(string $to, string $subject = '', string $body = ''): array
// Invia email di test. { success: bool, message: string }

rp_em_send_campaign(array $contacts, string $subject, string $body, int $rate_limit = 200000): array
// Invia campagna a lista contatti. { sent: int, failed: int, errors: string[] }

rp_em_preview_campaign(string $body, object $contact): string
// Genera anteprima HTML con personalizzazione.

rp_em_personalize(string $body, object $contact): string
// Sostituisce placeholder: {{first_name}}, {{email}}, {{site_name}}

rp_em_rate_limit_presets(): array
// Preset rate limit SES: fast (20/s), normal (5/s), slow (1/s)
```

### `campaigns.php`

```php
rp_em_get_campaigns(): array
// Tutte le campagne, ordinate per created_at DESC.

rp_em_get_campaign(string $id): ?array
// Singola campagna per ID.

rp_em_save_campaign(array $data): string
// Crea o aggiorna. Ritorna ID.

rp_em_delete_campaign(string $id): bool
// Elimina campagna e rimuove cron se schedulata.

rp_em_schedule_campaign(string $campaign_id, string $datetime): bool
// Schedula invio via WP-Cron a datetime locale del sito.

rp_em_unschedule_campaign(string $campaign_id): void
// Rimuove schedulazione cron.

rp_em_execute_campaign(string $campaign_id): array
// Esegue invio. Risolve contatti, invia, aggiorna stats.
// { sent: int, failed: int, errors: string[] }

rp_em_resolve_campaign_contacts(array $campaign): array
// Risolve contatti dalla sorgente configurata nella campagna.
```

---

## AJAX Endpoints

Tutti richiedono nonce `rp_em_nonce` + capability `manage_woocommerce`.

| Action | Metodo | Parametri | Risposta |
|---|---|---|---|
| `rp_em_ajax_send_test` | POST | `to`, `subject`, `body` | `{ success, message }` |
| `rp_em_ajax_get_modules` | POST | — | array moduli Hustle |
| `rp_em_ajax_get_contacts` | POST | `source_type`, `module_ids`, `csv_raw` | `{ contacts, counts }` |
| `rp_em_ajax_upload_csv` | POST | `csv_file` (FILE) | `{ contacts, count, filename }` |
| `rp_em_ajax_export_csv` | GET | `module_ids` | download CSV |
| `rp_em_ajax_get_campaigns` | POST | — | array campagne |
| `rp_em_ajax_save_campaign` | POST | `campaign` (JSON) | `{ id, campaign }` |
| `rp_em_ajax_delete_campaign` | POST | `campaign_id` | `{ message }` |
| `rp_em_ajax_send_campaign` | POST | `campaign_id` | `{ sent, failed, errors }` |
| `rp_em_ajax_schedule_campaign` | POST | `campaign_id`, `scheduled_at` | `{ message, campaign }` |
| `rp_em_ajax_preview_campaign` | POST | `campaign_id` | `{ html, subject, contact_count }` |

---

## Dipendenze

| Dipendenza | Tipo | Comportamento se assente |
|---|---|---|
| WP Mail SMTP | Opzionale | wp_mail() funziona comunque ma potrebbe usare PHP mail() nativo |
| Hustle | Opzionale | Tab "Hustle" vuoto, messaggio "Hustle non installato" |
| WooCommerce | Opzionale | Usa `manage_options` come fallback per capability check |

**Regola:** dipendenze verificate con `function_exists()` o table check, mai con `is_plugin_active()`.

---

## Convenzioni di Codice

### PHP
- Prefix **`rp_em_`** su tutte le funzioni pubbliche.
- Costanti con prefix `RP_EM_`.
- `wp_kses_post()` per sanitizzare HTML delle email.
- `sanitize_email()` per validare indirizzi email.
- Rate limit tramite `usleep()` per rispettare i limiti SES.

### UI
- CSS scopato sotto `#rpem`.
- 3 tabs: Test Email, Campagne, Contatti.
- Stesse CSS custom properties e design system del monorepo.
- Mobile responsive.

---

## Regole di Sviluppo per Claude Code

1. **Non modificare il routing email.** wp_mail() → WP Mail SMTP → SES e trasparente. Il plugin non deve mai bypassare questo flusso.
2. **Rate limiting e obbligatorio.** Mai rimuovere usleep() tra gli invii — SES ha limiti reali.
3. **Deduplicazione sempre.** Un contatto presente in piu sorgenti deve ricevere una sola email.
4. **Scheduling via WP-Cron.** Non usare cron di sistema o soluzioni esterne.
5. **Nessun `var_dump()` o `console.log()` nel codice committato.**
6. **L'UI deve funzionare su mobile** — il titolare usa lo strumento da telefono.
