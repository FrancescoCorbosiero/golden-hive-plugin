# ARCHITECTURE.md — RP Email Marketing

## Overview

RP Email Marketing e un plugin WordPress per l'invio di campagne email tramite `wp_mail()`, instradato su AWS SES via WP Mail SMTP.

## Flusso Email

```
UI Admin → AJAX → mailer.php → wp_mail() → WP Mail SMTP → AWS SES → Destinatario
```

Il plugin non interagisce mai direttamente con SES. Tutto passa per `wp_mail()` che WP Mail SMTP intercetta e instrada.

## Architettura Dati

### Campagne
Persistite in `wp_options` come array serializzato (chiave: `rp_em_campaigns`).

```php
[
    'id'           => 'abc12345',          // Generato da md5(uniqid())
    'name'         => 'Lancio Jordan 4',   // Nome interno
    'subject'      => 'Nuovi arrivi!',     // Oggetto email
    'body'         => '<h1>Ciao {{first_name}}</h1>',  // HTML con placeholder
    'source_type'  => 'hustle|csv|mixed',  // Sorgente contatti
    'module_ids'   => [1, 3],              // ID moduli Hustle (vuoto = tutti)
    'csv_contacts' => 'email,name\n...',   // CSV raw (opzionale)
    'rate_limit'   => 200000,              // Microsecondi tra invii
    'scheduled_at' => '2025-06-15 10:00',  // Datetime schedulazione (vuoto = manuale)
    'status'       => 'draft',             // draft|scheduled|sending|sent|failed
    'stats'        => [                    // Risultati invio
        'sent'   => 48,
        'failed' => 2,
        'errors' => ['john@bad.com: wp_mail failed']
    ],
    'created_at'   => '2025-06-10 14:30:00',
    'updated_at'   => '2025-06-10 15:00:00',
]
```

### Contatti
Non persistiti nel plugin — letti on-demand da:
1. **Hustle DB** (`hustle_entries` + `hustle_entries_meta`) per iscritti optin
2. **CSV raw** per contatti importati manualmente

La deduplicazione avviene in memoria ad ogni richiesta.

## Scheduling

```
Utente → "Programma Invio" → rp_em_schedule_campaign()
    → wp_schedule_single_event($timestamp, 'rp_em_cron_send_campaign', [$id])
    → WP-Cron trigger → rp_em_execute_campaign($id)
```

WP-Cron e pseudo-cron (si attiva solo quando c'e traffico). Per scheduling preciso, il server deve avere un real cron job che chiama `wp-cron.php`.

## Rate Limiting

SES in produzione supporta tipicamente 14 msg/sec. I preset:

| Preset | usleep() | Throughput | Uso |
|---|---|---|---|
| Veloce | 50ms | ~20/sec | Alto volume, SES produzione |
| Normale | 200ms | ~5/sec | Default sicuro |
| Lento | 1000ms | ~1/sec | Debug, SES sandbox |

## Personalizzazione Email

Placeholder supportati nel body HTML:
- `{{first_name}}` → display_name del contatto o "Amico"
- `{{email}}` → email del contatto
- `{{site_name}}` → nome del sito WordPress

## Layer UI

3 tab nella pagina admin:

1. **Test Email** — form semplice per verificare il routing
2. **Campagne** — lista campagne + editor con preview
3. **Contatti** — browse iscritti, export CSV, import CSV
