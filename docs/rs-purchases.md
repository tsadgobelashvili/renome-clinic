# RS purchasing and retail products

`products` remains the retail catalog. Its normal list and existing Cashier sale selector show only `catalog_status=sellable`; name, selling price and active status remain the retail fields. The old resource route is retained. A separate review tab keeps ambiguous legacy records accessible for an owner's explicit decision.

`purchases` and `purchase_items` remain the analytical document/line tables. `purchase_products` is their supplier-scoped material identity and remembered Direction mapping, **not** another expense-category registry. Direction references the existing `expense_categories` rows with `classification_dimension=direction`.

## Identity and classification

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
