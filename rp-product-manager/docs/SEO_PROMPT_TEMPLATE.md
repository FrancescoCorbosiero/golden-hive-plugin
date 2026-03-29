# SEO Prompt Template — ResellPiacenza

Questo è il prompt master per generare contenuto SEO-ottimizzato per i prodotti.
Usato insieme a `rp_update_product()` per popolare i campi in modo programmatico.

---

## Come si usa con il Plugin

1. Genera il contenuto SEO con questo prompt (su Claude o altro LLM)
2. Il JSON output può essere passato direttamente a `rp_update_product()` o incollato nel JSON Editor della UI

**Mapping campi prompt → funzione PHP:**

| Campo prompt | Campo `rp_update_product()` |
|---|---|
| Title | `meta_title` |
| Meta Description | `meta_description` |
| Focus Keyword | `focus_keyword` |
| Slug | `slug` |
| Short Description (HTML) | `short_description` |
| Page Content (HTML) | `description` |
| SKU | `sku` |

---

## Prompt Master

```
Ho bisogno di contenuto SEO-ottimizzato per il mio e-commerce italiano di sneakers (ResellPiacenza).

## PRODOTTO:
- Nome: [Nome completo prodotto]
- Brand: [Brand]
- Modello: [Modello]
- Colorway: [Colorway ufficiale]
- Prezzo: €[Prezzo]
- Taglie: [Range taglie EU]
- Materiale: [Materiali dettagliati]
- Target: [Uomo/Donna/Unisex/Kids]
- Release Date: [Data rilascio]
- Style Code: [Codice stile]
- Retail Price: $[Prezzo retail originale]
- Limited Edition: [Sì/No]
- Designer: [Nome designer se rilevante]
- Caratteristiche uniche: [Features speciali, storia, collaborazioni]

## OUTPUT RICHIESTO (JSON pronto per rp_update_product):

Rispondi con un JSON valido con questa struttura:
{
  "sku": "BRAND-MODEL-ANNO",
  "slug": "brand-model-colorway-anno",
  "meta_title": "...",
  "meta_description": "...",
  "focus_keyword": "...",
  "short_description": "<p>HTML...</p>",
  "description": "<div>HTML lungo...</div>"
}

## REGOLE SEO:

### Focus Keyword
- 2-4 parole chiave, cosa cercherebbe la gente
- Deve apparire in: meta_title, slug, meta_description, H2, tutti gli H3, primo paragrafo (2x), short_description (2x)

### meta_title
- Max 60 caratteri
- Formato: [Brand] [Modello] [Anno/Feature] | Shop Online

### slug
- Lowercase con trattini
- Esempio: nike-air-max-90-og-2024

### meta_description
- Max 160 caratteri
- Include style code, feature chiave
- EVITA "acquista ora/compra online"
- USA "Spedizione Italia. Ordina Online."

### short_description
- HTML 60-80 parole
- Focus keyword ripetuta 2 volte
- Include: nome prodotto in <strong>, feature principale, materiali, vestibilità

### description (contenuto lungo)
- HTML 400-500 parole con CSS inline
- Struttura obbligatoria:
  - Div wrapper con font-family e line-height
  - H2 con focus keyword (font-size 1.65em, font-weight 700)
  - Primo paragrafo con focus keyword 2 volte
  - Box colorato con gradient per storia/contesto prodotto
  - H3 "Caratteristiche [focus keyword]" con lista 10+ punti
  - H3 "Come Indossare [focus keyword]" con styling tips
  - H3 "Vestibilità e Taglie [focus keyword]" con fit guide
  - H3 "Cura e Manutenzione"
  - Box FAQ con 4 domande (background #f8f8f8)
    1. Differenza con modelli simili
    2. Dettaglio tecnico specifico
    3. Come vestono / sizing
    4. Disponibilità / esclusività

### Linguaggio
- Italiano NATURALE, appassionato, informativo
- MAI: "Acquista ora", "Compra online", "Non perdere l'occasione"
- USA: "Shop Online", "Ordina Online", "Spedizione in tutta Italia"
- NO keyword stuffing — integrazione naturale
- Power words: Esclusivo, Definitivo, Originale, Premium, Autentico, Iconico, Leggendario
```

---

## Esempio di Utilizzo Programmatico

```php
// Dopo aver generato il JSON con il prompt sopra:
$seo_data = [
    'sku'               => 'NK-AM90-OG-2024',
    'slug'              => 'nike-air-max-90-og-2024',
    'meta_title'        => 'Nike Air Max 90 OG 2024 | Shop Online',
    'meta_description'  => 'Nike Air Max 90 OG 2024, colorway White/Black, style code CT1045-100. Taglia EU 40-46. Spedizione Italia. Ordina Online.',
    'focus_keyword'     => 'nike air max 90 og',
    'short_description' => '<p>Le <strong>Nike Air Max 90 OG 2024</strong>...',
    'description'       => '<div style="font-family:...">...',
];

$result = rp_update_product($product_id, $seo_data);
```
