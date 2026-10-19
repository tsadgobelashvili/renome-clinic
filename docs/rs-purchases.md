# RS purchasing and retail products

`products` remains the retail catalog. Its normal list and existing Cashier sale selector show only `catalog_status=sellable`; name, selling price and active status remain the retail fields. The old resource route is retained. A separate review tab keeps ambiguous legacy records accessible for an owner's explicit decision.

`purchases` and `purchase_items` remain the analytical document/line tables. `purchase_products` is their supplier-scoped material identity and remembered Direction mapping, **not** another expense-category registry. Direction references the existing `expense_categories` rows with `classification_dimension=direction`.

## Purchase Analysis metrics

Navigation remains Direction → Product Group → Product → Purchase History, with a small inline Back control and breadcrumb. Period presets are 7 days, 1/3/6 months, 1 year, All (default), and Custom; month/year presets are inclusive through today using no-overflow calendar subtraction. Custom exposes From/To. Existing direction, group, product, supplier and payment filters combine as before.

Group rows show distinct document count, comparable-unit quantity, amount and latest purchase in the selected period. Product count/quantity/amount also use that period and the selected filters. Product latest purchase, days since latest purchase, and average interval use the product's full valid history, independently of period/supplier/payment filters. Interval is `(last distinct purchase date - first distinct purchase date) / (number of distinct purchase dates - 1)`, equivalent to the mean of successive date gaps. Multiple lines in a document count once for document count; separate same-day documents count separately but share one frequency date. Fewer than two dates yields no interval. Days since last purchase uses the application calendar date.

Eligibility is read-only and shared by every analysis query. It reuses retained import `validated`/`validation_errors`/`source_rows` evidence and the existing distinction between manual/legacy RS documents and imported documents lacking source evidence. Explicit cancelled/deleted/invalid text statuses in retained `raw.headers`/`raw.cells` are excluded. There is no normalized RS cancellation or soft-delete column in the current Purchase schema; absent statuses and undocumented numeric status codes cannot be inferred. Deleted records naturally disappear through the document join. A payment reversal or a Bank P&L exclusion alone is not RS document cancellation. Signed legacy credit/return lines are not removed merely because they are negative; importer acceptance rules remain unchanged.

Product history remains newest first under the selected report filters, showing original date, supplier, document number, description, quantity, unit, unit price and line total. Missing supplier/document values display a dash. Mixed-unit product totals do not display a summed quantity or a cross-unit price comparison; unit prices remain visible per original line. Optional price-change summary/charts are omitted.

Counts, sums, latest dates and frequency are SQL aggregates/subqueries by catalog product ID. No history is loaded into browser state for calculation, no row-level frequency queries are issued, and pagination remains server-side. Measured product retrieval is one SQL query, or two for count + paginated results, independent of product count. These queries perform no data writes or classification changes; the existing explicit classification editor is unchanged.

## Identity and classification

Purchase Analysis product rows and product purchase-history rows expose a modal **რედაქტირება** action. Category uses the existing product Direction (`expense_direction_id`), and Product Group uses `purchase_product_group_id`. Only **შენახვა** persists the product-level classification through the catalog's shared atomic validation service. This regroups all analytical lines linked to that supplier-scoped catalog product and future recognized imports; it does not rewrite RS lines or existing Finance classifications. Other catalog identities remain independent. Saving refreshes table records without resetting report filters, search, drill-down context, or pagination.

`PurchaseCatalog` chooses RS product code first, supplier product code second, normalized name last. The SHA-256 identity key includes the supplier ID and identity kind. Codes remain exact strings (including leading zeroes). Name fallback trims and collapses whitespace and lowercases the name. Similar names or identical codes from different suppliers do not share mappings.

The importer recognizes optional `RS Product Code` / `RS Item Code` / `საქონლის კოდი` and `Supplier Item Code` / `Product Code` / `მომწოდებლის კოდი` headers. Missing codes are safe. Providing a new code where an earlier import had only a name intentionally creates an unclassified identity rather than guessing that they match.

Each line preserves its imported `item_name`, quantities, prices, totals, VAT and duplicate hash. Inline Direction changes update the purchasing identity, so all its analytical lines and future imports use the current mapping. They do not rewrite financial transactions. No keyword-based Direction guessing remains. The old `PurchaseCategoryResolver` was removed from the application.

## Data preservation

Migration `2026_09_17_180000_separate_purchase_catalog_from_sellable_products` adds the purchasing registry, nullable `purchase_product_id` and `item_name` to purchase lines, and `catalog_status` to retail products. Legacy `product_id`, category IDs, products, sale history, purchase documents, amounts and duplicate hashes remain intact.

An existing product with a positive selling price or sale history stays sellable. Others are marked `review`, never deleted. Historical purchase lines get supplier-scoped purchasing identities, initially unclassified: old keyword guesses are not treated as confirmed mappings. The migration deliberately refuses rollback once the new catalog contains records, preventing accidental loss of new imports/mappings.

## UI and financial boundary

Finance → **RS / შესყიდვები** lists documents, supplier, total, line count and classification status. **შეძენილი პროდუქტები** opens the line table with date, supplier, Direction, search and **უკატეგორიო პროდუქტები** filters. Directions save inline. Document **მიმართულებები** opens a SQL-summed breakdown only on demand. Existing owner-only permissions remain.

RS import/classification writes only purchasing metadata. It creates no Finance, Bank, Cashier or payment entries. No BOG ↔ RS matching is implemented: future matching must associate a real BOG financial expense with these analytical lines without recognizing a second expense.

Focused checks: `PurchasesTest`, `RsPurchaseSeparationTest`, existing product-sale regression tests, and the Finance/Bank accounting regression tests. No full suite is required.

## Verification on 2026-09-17

- Applied only the new migration locally. Original-column checksums matched before/after for products, purchases/lines, product sales/lines, payments, Cashier, Clinic/Israeli Finance, direct expenses and bank transactions.
- Both existing local products have sales/history and remain sellable; there were no existing local purchase documents or ambiguous products.
- Final focused run: **86 tests, 81 passed, 4 failures and 1 error**, 520 assertions. All 71 purchase/RS/Finance Overview/Bank critical tests passed, plus 10 existing product-sale tests.
- The five `ProductSaleTest` problems were reproduced with this task's two sales-selector/service changes removed: two Cashier item-description assertions, one patient product-history label assertion, and two tests still asserting the old Finance response shape. No unrelated UI/reporting code was changed to hide those failures.
- Pint and `git diff --check` passed. No full suite or live RS import was run. No commit.

## Files in this change

Added:

- `database/migrations/2026_09_17_180000_separate_purchase_catalog_from_sellable_products.php`
- `app/Models/PurchaseProduct.php`
- `app/Services/PurchaseCatalog.php`
- `app/Filament/Resources/Purchases/Pages/PurchaseItems.php`
- `resources/views/filament/resources/purchases/items.blade.php`
- `resources/views/filament/resources/purchases/breakdown.blade.php`
- `tests/Feature/RsPurchaseSeparationTest.php`
- `docs/rs-purchases.md`

Updated:

- `app/Models/Product.php`, `PurchaseItem.php`, `ExpenseCategory.php` (RS Direction reference protection)
- `app/Services/PurchaseImportService.php`, `ProductSaleService.php` (sellable eligibility only)
- `app/Filament/Support/ProductSaleForm.php` (sellable selector only)
- `app/Filament/Resources/ProductMaterials/ProductMaterialResource.php`, `Pages/ListProductMaterials.php`
- `app/Filament/Resources/Purchases/PurchaseResource.php`, `Pages/ListPurchases.php`, `Schemas/PurchaseForm.php`, `Tables/PurchasesTable.php`
- `tests/Feature/PurchasesTest.php`

Removed: `app/Services/PurchaseCategoryResolver.php` (obsolete keyword guesses).

## RS export compatibility fix

The original `report (5).csv` uses `საქონლის დასახელება`, `რაოდ.`, `ზომის ერთეული`, `საქონლის ფასი`, `ზედნადების ნომერი` and `გააქტიურების თარიღი`. The importer previously did not recognize these headers and therefore never reached persistence. Its zero-row/no-error result incorrectly triggered a success notification. Dates such as `01-სექ-2026 11:18:22` also require Georgian month parsing.

`PurchaseImportService` now recognizes those actual export headers and dates. It still writes `suppliers`, `purchase_products`, `purchases` and `purchase_items`, exactly the models read by the RS UI. Document lookup reuses supplier + RS source document ID (waybill number fallback), with supplier locking, so a subsequent export can append new lines without creating a second document. Missing identifiers use a file fingerprint/date fallback. Existing line hashes and legacy-hash compatibility remain.

`ListPurchases` refreshes table records and reports documents, items, duplicates, unclassified items and invalid rows. Unrecognized/empty files and duplicate-only imports never show generic success. No new migration, Finance behavior or BOG matching was added.

`tests/fixtures/rs-items-georgian.csv` is a sanitized fixture with the original export's column structure. `RsImportFlowTest` covers upload through the actual action, all three views, mapping, partial/repeated imports, invalid rows and financial isolation. The optional original-file check uses `RS_IMPORT_SAMPLE` and the isolated SQLite test database: **20 documents / 37 items / 0 rejected**, then **0 new / 37 duplicates**. The original CSV and local database were left unchanged.
