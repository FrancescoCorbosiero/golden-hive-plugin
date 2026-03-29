# Catalog Structure — ResellPiacenza

## File Catalogo

| File | Descrizione | Prodotti |
|---|---|---|
| `catalogo.csv` | Catalogo principale (Nike, Jordan, Adidas, New Balance…) | ~217 righe |
| `catalog_corteiz.csv` | Sub-catalogo brand Corteiz | separato |
| `catalog.json` | Versione JSON del catalogo principale | stesso contenuto |

---

## Struttura CSV

```
Sezione, Marca, Sottocategoria, SKU, Titolo, Query, Taglie, Prezzo, Prezzo Scontato
```

### Esempio di riga

```csv
Sneakers,Nike,Dunk Low,NK-DL-BLK-LIME,Nike Dunk Low Black Lime Glow,nike dunk low black lime glow blue,40|40.5|41|42|42.5|43|44,189.00,
```

### Mapping CSV → WooCommerce

| Colonna CSV | Campo WooCommerce | Note |
|---|---|---|
| Sezione | Categoria (`product_cat`) | Es. "Sneakers" |
| Marca | Attributo Brand + Categoria | Es. "Nike" |
| Sottocategoria | Categoria figlia | Es. "Nike Dunk Low" |
| SKU | `_sku` prodotto padre | Usato come base per SKU varianti |
| Titolo | `post_title` | Nome prodotto |
| Query | `rank_math_focus_keyword` | Focus keyword Rank Math |
| Taglie | Varianti `pa_taglia` | Pipe-separated, EU sizes |
| Prezzo | `_regular_price` | Prezzo di listino |
| Prezzo Scontato | `_sale_price` | Vuoto = nessun saldo |

---

## SKU Convention

**Prodotto padre:** `BRAND-MODEL-DETAIL`
```
NK-DL-BLK-LIME    → Nike Dunk Low Black Lime Glow
NK-AM90-OG        → Nike Air Max 90 OG
AJ4-BLACKCAT-2025 → Air Jordan 4 Black Cat 2025
```

**Variante (taglia):** `SKU-PADRE-T{TAGLIA}`
```
NK-DL-BLK-LIME-T40
NK-DL-BLK-LIME-T40.5
NK-DL-BLK-LIME-T41
```

---

## Tipo Prodotto WooCommerce

Tutti i prodotti con varianti taglia → `variable`
Prodotti senza taglie (accessori, ecc.) → `simple`

---

## Attributo Taglia

Termine WooCommerce: `pa_taglia`
Slug: `taglia`

```php
// Come viene cercato in rp_get_product_variations():
preg_match('/(taglia|size|misura|eu|uk|us|fr|cm)/i', $attribute_key)
```

Se il negozio usa un nome attributo diverso (es. `pa_size`, `pa_eu`), aggiornare la regex in `variations.php`.

---

## Logica di Import (da implementare in P0)

Pseudocodice per `rp_import_product_from_row()`:

```
1. Parse riga CSV
2. Cerca prodotto per SKU (evita duplicati)
   → Se esiste: update
   → Se non esiste: create
3. Crea prodotto variabile con:
   - name = Titolo
   - sku = SKU
   - status = 'draft' (mai pubblicare direttamente)
   - regular_price = Prezzo (sul padre, per fallback)
4. Per ogni taglia in Taglie.split('|'):
   - Crea variante con attributo pa_taglia = taglia
   - sku variante = SKU + '-T' + taglia
   - regular_price = Prezzo
   - sale_price = Prezzo Scontato (se presente)
   - stock_quantity = 1 (default, da aggiornare manualmente)
5. Assegna categorie: Sezione > Marca > Sottocategoria
6. Imposta focus_keyword = Query
7. Ritorna product_id
```
