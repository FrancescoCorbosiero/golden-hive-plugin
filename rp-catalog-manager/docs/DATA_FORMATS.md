# DATA_FORMATS.md — Esempi Annotati dei Formati JSON

Questo file è la reference definitiva per i due formati di output.
Usalo quando hai dubbi su quale campo includere, come nominarlo, o quale tipo usare.

---

## Formato CATALOG — Esempio Completo Annotato

```jsonc
{
  // Sempre presente. ISO 8601 con timezone.
  "generated_at": "2025-03-29T14:30:00+01:00",

  // "catalog" | "full_export"
  "mode": "catalog",

  // Calcolato dopo la costruzione del tree.
  "summary": {
    "total_products": 217,
    "total_in_stock": 143,         // prodotti con stock_status != 'out'
    "total_variants": 1840,        // somma di variant_count di tutti i prodotti
    "total_variants_in_stock": 520,
    "categories": 12,              // numero di sottocategorie uniche
    "brands": 8,                   // numero di brand unici (livello Marca)
    "generated_in_seconds": 3.4    // performance info
  },

  "tree": {
    // Livello 1: Sezione (es. "Sneakers", "Abbigliamento")
    "Sneakers": {

      // Livello 2: Marca
      "Nike": {

        // Livello 3: Sottocategoria
        "Nike Dunk Low": [

          // Un oggetto per prodotto. NO dettaglio varianti.
          {
            // ID WooCommerce del prodotto padre
            "id": 123,
            "name": "Nike Dunk Low Black Lime Glow",
            "sku": "NK-DL-BLK-LIME",
            "slug": "nike-dunk-low-black-lime-glow",

            // "publish" | "draft" | "private"
            "status": "publish",
            "permalink": "https://resellpiacenza.it/prodotto/nike-dunk-low-black-lime-glow/",

            // Aggregazione taglie dalle varianti
            "sizes": {
              // Stringa human-readable del range
              "range": "40 – 44",
              // Tutte le taglie presenti come variante (in_stock + out_of_stock)
              "available": ["40", "40.5", "41", "42", "42.5", "43", "44"],
              // Solo varianti con stock_status = 'instock'
              "in_stock": ["41", "42", "43"],
              // Solo varianti con stock_status = 'outofstock'
              "out_of_stock": ["40", "40.5", "42.5", "44"],
              // Conteggi rapidi
              "count_total": 7,
              "count_in_stock": 3
            },

            // Aggregazione prezzi da tutte le varianti
            "pricing": {
              // float, non stringa. null se nessun prezzo impostato.
              "regular_min": 179.00,
              "regular_max": 179.00,
              // Media aritmetica. Utile se prezzi differenziano per taglia.
              "regular_avg": 179.00,
              // true se almeno una variante ha sale_price impostato
              "has_sale": false,
              "sale_min": null,
              "sale_max": null,
              // Codice valuta ISO 4217
              "currency": "EUR"
            },

            // Stato stock aggregato
            "stock": {
              "variant_count": 7,
              "in_stock_count": 3,
              "out_of_stock_count": 4,
              // "full" | "partial" | "out" | "unmanaged"
              "stock_status": "partial"
            },

            // Dati SEO da Rank Math (meta field)
            "seo": {
              "focus_keyword": "nike dunk low black lime glow",
              "meta_title": "Nike Dunk Low Black Lime Glow | Shop Online",
              // boolean invece del contenuto HTML — il catalog non è verbose
              "has_description": true,
              "has_short_description": true,
              // Se false: questo prodotto ha SEO incompleto → utile per audit
              "seo_complete": true
            },

            // Date come stringhe ISO date (non datetime — il catalog è sintetico)
            "dates": {
              "created": "2024-11-10",
              "modified": "2025-03-01"
            }
          }

          // ... altri prodotti nella stessa sottocategoria
        ],

        "Nike Dunk High": [
          // ...
        ]
      },

      "Jordan": {
        "Air Jordan 4": [
          {
            "id": 456,
            "name": "Air Jordan 4 Black Cat 2025",
            "sku": "AJ4-BLACKCAT-2025",
            // Prodotto con sale_price su alcune varianti
            "pricing": {
              "regular_min": 320.00,
              "regular_max": 360.00,  // taglie grandi a prezzo maggiore
              "regular_avg": 335.71,
              "has_sale": true,
              "sale_min": 289.00,
              "sale_max": 320.00,
              "currency": "EUR"
            },
            "stock": {
              "variant_count": 8,
              "in_stock_count": 8,
              "out_of_stock_count": 0,
              "stock_status": "full"  // tutte le taglie disponibili
            }
            // ... altri campi
          }
        ]
      }
    }
  }
}
```

---

## Formato FULL EXPORT — Esempio Completo Annotato

```jsonc
{
  "generated_at": "2025-03-29T14:35:00+01:00",
  "mode": "full_export",
  "summary": {
    // Stesso blocco summary del catalog
    "total_products": 217,
    "total_in_stock": 143,
    "total_variants": 1840
  },

  "tree": {
    "Sneakers": {
      "Nike": {
        "Nike Dunk Low": [
          {
            "id": 123,
            "name": "Nike Dunk Low Black Lime Glow",
            "sku": "NK-DL-BLK-LIME",
            "slug": "nike-dunk-low-black-lime-glow",
            "status": "publish",
            "type": "variable",   // "simple" | "variable" — incluso nel full
            "permalink": "https://resellpiacenza.it/prodotto/nike-dunk-low-black-lime-glow/",

            // Prezzi del prodotto padre (fallback WooCommerce, non usati se variabile)
            "pricing": {
              "regular_price": "179.00",   // stringa, come da WooCommerce
              "sale_price": "",
              "price": "179.00"            // prezzo corrente (regolare o scontato)
            },

            // Stock del prodotto padre
            "stock": {
              "manage_stock": false,
              "stock_quantity": null,
              "stock_status": "instock"
            },

            // HTML completo — non troncato
            "content": {
              "description": "<div style=\"font-family:...\">...</div>",
              "short_description": "<p>Le <strong>Nike Dunk Low...</strong></p>"
            },

            // SEO completo con tutti i campi Rank Math
            "seo": {
              "focus_keyword": "nike dunk low black lime glow",
              "meta_title": "Nike Dunk Low Black Lime Glow | Shop Online",
              "meta_description": "Nike Dunk Low Black Lime Glow, style code... Spedizione Italia.",
              "slug": "nike-dunk-low-black-lime-glow"
            },

            // URL immagini (risolti, non ID)
            "media": {
              "featured_image_url": "https://resellpiacenza.it/wp-content/uploads/2024/11/nike-dunk-low-blk-lime-lateral.jpg",
              "gallery_urls": [
                "https://resellpiacenza.it/wp-content/uploads/2024/11/nike-dunk-low-blk-lime-medial.jpg",
                "https://resellpiacenza.it/wp-content/uploads/2024/11/nike-dunk-low-blk-lime-sole.jpg"
              ]
            },

            // Attributi del prodotto (non delle singole varianti)
            "attributes": {
              "pa_taglia": ["40", "40.5", "41", "42", "42.5", "43", "44"]
            },

            // Array completo varianti — questo è ciò che manca nel CATALOG
            "variants": [
              {
                "variation_id": 1001,
                // Taglia estratta con la stessa regex di rp-product-manager
                "size": "40",
                "sku": "NK-DL-BLK-LIME-T40",
                // Stringa come da WooCommerce, non float
                "regular_price": "179.00",
                "sale_price": "",
                "stock_quantity": 0,
                // "instock" | "outofstock" | "onbackorder"
                "stock_status": "outofstock",
                // "publish" | "private" (variante nascosta)
                "status": "publish"
              },
              {
                "variation_id": 1002,
                "size": "40.5",
                "sku": "NK-DL-BLK-LIME-T40.5",
                "regular_price": "179.00",
                "sale_price": "",
                "stock_quantity": 1,
                "stock_status": "instock",
                "status": "publish"
              }
              // ... tutte le altre varianti
            ],

            // Datetime completo nel full export
            "dates": {
              "created": "2024-11-10T09:00:00",
              "modified": "2025-03-01T16:22:00"
            }
          }
        ]
      }
    }
  }
}
```

---

## Regole di Naming dei Campi

Queste regole garantiscono coerenza tra i due formati e con gli altri plugin RP.

| Regola | Esempio corretto | Esempio sbagliato |
|---|---|---|
| snake_case per tutte le chiavi | `stock_status` | `stockStatus`, `StockStatus` |
| Prezzi come float nel CATALOG | `"regular_min": 179.00` | `"regular_min": "179.00"` |
| Prezzi come stringa nel FULL | `"regular_price": "179.00"` | `"regular_price": 179.0` |
| Boolean veri per flag | `"has_sale": false` | `"has_sale": "false"`, `"has_sale": 0` |
| null per valori assenti | `"sale_min": null` | `"sale_min": ""`, `"sale_min": 0` |
| Date ISO nel CATALOG (solo data) | `"created": "2024-11-10"` | `"created": "10/11/2024"` |
| Datetime ISO nel FULL | `"created": "2024-11-10T09:00:00"` | |
| URL assoluti sempre | `"permalink": "https://..."` | `"permalink": "/prodotto/..."` |

**Perché prezzi float nel CATALOG ma stringa nel FULL?**
Nel CATALOG i prezzi sono già aggregati (min/max/avg) — hanno senso come numeri per calcoli futuri.
Nel FULL sono raw da WooCommerce — mantenerli come stringa evita di alterare il dato originale (WooCommerce usa stringhe internamente).
