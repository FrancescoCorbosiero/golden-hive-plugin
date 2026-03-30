# BULK_IMPORT.md — Formato JSON per Importazione Prodotti

## Panoramica

Il Bulk Import permette di creare prodotti WooCommerce in massa da un file JSON.
E' separato dal **Roundtrip** (che serve per backup/restore di prodotti esistenti).

**Bulk Import** = creare prodotti nuovi (o aggiornare esistenti per SKU)
**Roundtrip** = snapshot e ripristino dello stato WooCommerce

---

## Schema JSON

Il file deve contenere un oggetto root con un array `products`:

```json
{
  "products": [
    { ... },
    { ... }
  ]
}
```

Oppure direttamente un array `[ { ... }, { ... } ]` — entrambi i formati sono accettati.

---

## Campi Prodotto

| Campo | Tipo | Obbligatorio | Note |
|---|---|---|---|
| `name` | string | **Si** | Nome del prodotto |
| `sku` | string | Consigliato | Usato per matching. Se esiste gia, aggiorna invece di creare |
| `type` | string | No | `"simple"` (default) o `"variable"` |
| `status` | string | No | `"publish"` (default), `"draft"`, `"private"` |
| `regular_price` | string | Si (simple) | Prezzo regolare. Per variable: lasciare vuoto, i prezzi vanno sulle varianti |
| `sale_price` | string | No | Prezzo scontato |
| `description` | string | No | Descrizione HTML completa |
| `short_description` | string | No | Descrizione breve HTML |
| `manage_stock` | bool | No | Se gestire lo stock. Default: `false` |
| `stock_quantity` | int | No | Quantita in stock (richiede `manage_stock: true`) |
| `stock_status` | string | No | `"instock"`, `"outofstock"`, `"onbackorder"`. Default: `"instock"` |
| `weight` | string | No | Peso (unita configurata in WooCommerce) |
| `category_ids` | int[] | No | Array di term_id delle categorie |
| `tag_ids` | int[] | No | Array di term_id dei tag |
| `meta_title` | string | No | Titolo SEO (Rank Math) |
| `meta_description` | string | No | Descrizione SEO (Rank Math) |
| `focus_keyword` | string | No | Focus keyword SEO (Rank Math) |
| `attributes` | object | Si (variable) | Attributi del prodotto. Vedi sotto |
| `variations` | array | Si (variable) | Array di varianti. Vedi sotto |

### Attributi (per prodotti variable)

L'oggetto `attributes` mappa il nome dell'attributo WooCommerce al suo setup:

```json
{
  "attributes": {
    "pa_taglia": {
      "options": ["40", "40.5", "41", "42", "42.5", "43", "44"],
      "visible": true,
      "variation": true
    }
  }
}
```

| Campo | Tipo | Note |
|---|---|---|
| `options` | string[] | Tutti i valori possibili |
| `visible` | bool | Visibile nella pagina prodotto. Default: `true` |
| `variation` | bool | Usato per le varianti. Default: `true` |

**Nota:** il prefisso `pa_` indica un attributo globale WooCommerce.
Per attributi custom (non registrati nella tassonomia), ometti il prefisso.

### Varianti

Ogni variante e un oggetto nell'array `variations`:

```json
{
  "variations": [
    {
      "attributes": { "pa_taglia": "40" },
      "sku": "NK-DL-PANDA-T40",
      "regular_price": "179.00",
      "sale_price": "",
      "manage_stock": true,
      "stock_quantity": 2,
      "stock_status": "instock",
      "status": "publish",
      "weight": ""
    }
  ]
}
```

| Campo | Tipo | Obbligatorio | Note |
|---|---|---|---|
| `attributes` | object | **Si** | Mappa attributo → valore per questa variante |
| `sku` | string | Consigliato | SKU della variante |
| `regular_price` | string | **Si** | Prezzo regolare |
| `sale_price` | string | No | Prezzo scontato |
| `manage_stock` | bool | No | Default: `false` |
| `stock_quantity` | int | No | Quantita stock |
| `stock_status` | string | No | Default: `"instock"` |
| `status` | string | No | Default: `"publish"` |
| `weight` | string | No | Peso |

---

## Modalita di Import

### 1. Crea sempre (`create`)
Crea un nuovo prodotto per ogni entry nel JSON. Non controlla duplicati.

### 2. Crea o aggiorna (`create_or_update`)
Per ogni entry:
- Se trova un prodotto con lo stesso `sku` → aggiorna
- Se non trova → crea nuovo

Il matching avviene **solo per SKU** (non per nome o ID).

---

## Flusso UI

1. **Tab "Import"** → trascina/carica file `.json`
2. **Seleziona modalita** → "Crea sempre" o "Crea o aggiorna"
3. **Preview** → tabella con: nome, SKU, tipo, varianti, stato (nuovo/aggiorna)
4. **Conferma** → crea/aggiorna i prodotti
5. **Risultato** → tabella con esito per prodotto (creato/aggiornato/errore)

---

## Limiti

- Immagini: non supportate in v1 (richiedono upload separato)
- Attributi: vengono creati come attributi custom se `pa_*` non esiste nella tassonomia
- Categorie/tag: devono esistere gia (passare `category_ids`, non nomi)
- Max ~200 prodotti per file (limite pratico per timeout PHP)

---

## File di Esempio

- `docs/samples/bulk-import-simple.json` — prodotti simple
- `docs/samples/bulk-import-variable.json` — prodotti variable con varianti
- `docs/samples/bulk-import-mixed.json` — mix di simple e variable
