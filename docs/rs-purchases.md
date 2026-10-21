# RS purchasing and retail products

## Historical import safeguards (20 October 2026)

Raw imports remain analytical staging only: no Finance/Cashier/Bank/advance/Israeli postings. Do not use Cash payment, Bank matching or Advance settlement merely to make historical data appear in analysis.

Deployment prerequisite: `purchase_product_aliases` already belongs to **2026_10_16_120000_link_manual_expenses_to_shared_purchase_catalog**. Apply the existing migration through the normal deployment process; no duplicate alias migration or guessed backfill was added. Import fails clearly before creating staging records when that table is absent. **2026_10_20_120000_protect_rs_document_identity** adds unique `(source, supplier_id, source_document_id)` protection and refuses conflicting keys without deleting/merging/re-keying records. **2026_10_20_130000_widen_rs_purchase_quantity_precision** widens only `purchase_items.quantity` from `(12,3)` to `(15,6)`, preserving the nine integer digits and historical numeric values; its rollback deliberately never shrinks precision. PostgreSQL import checks the deployed scale before accepting source data. None of these migrations was applied to existing application data during implementation.

Recognized explicit cancellation text is retained as `rs_import_payload.document_kind=cancelled`; explicit return/credit/correction text becomes `return`. Unsupported status/type labels, including undocumented numeric enums, become `review`. Original source fields remain in the retained payload. These audit-only kinds are excluded from normal purchase analysis and blocked by the shared reconciliation guard used by all accounting/linking paths. Existing raw cancellation/return evidence is also checked without rewriting historical records. Signed returns are retained without absolute-value conversion; unsupported negative normal purchases are rejected and reported. Numeric RS type meanings are deliberately not guessed.

Rows require matching CSV width (Excel may omit optional trailing empty cells), supplier, product, numeric quantity/unit price, a strictly valid source date and stable document ID/number. A supplied Unit column requires a nonempty textual value; older formats with no Unit column retain NULL rather than inventing a unit. Missing dates never become today. A present authoritative line-amount column may not contain an empty value; an optional document-total column may declare its value once per document. Source totals remain authoritative; multiplication is permitted only for structurally validated formats without a line-total header. Quantity is normalized exactly with Brick Math at six decimal places without rounding: `0.3333` stores `0.333300`, `1.234567` remains exact, and unsupported seventh-place values are rejected. Monetary amounts keep two decimals, with exact multiplication followed by final monetary rounding only when fallback is legitimate. Independent supplied document totals still reconcile within 0.01 GEL. Unknown/numeric unit representations require source review rather than guessing a label.

Overlap keeps established supplier/code catalog classifications. New identities remain unclassified; aliases are reused. Exact document-number fallback can reuse an existing ID when an export adds an RS ID. Conflicting IDs, dates, multiple candidates or the same identifier/date under another supplier require review. No fuzzy financial identity matching is used. Existing historical line-hash compatibility and identical-line multiplicity remain. Accounted documents cannot receive additional/changed lines or rewritten source metadata. Without stable line IDs, genuinely changed contents still require manual source review: import is append/skip, not replacement synchronization.

One uploaded file is streamed into a private disk-backed SQLite workspace, then processed through short Livewire requests of at most 500 source entries, each keeping the existing <=100-row ERP transactions and row savepoints. PDO SQLite is required for checkpoint storage even when ERP uses PostgreSQL. No queue worker is required. Source occurrences, touched documents, alias/error scopes and source entries are disk-backed (2 MiB SQLite page cache), not full-file PHP arrays. Payloads publish once per affected document per transaction; supplier/document/product caches are released at that boundary. At EOF, finalization runs in groups of 50 documents. A document spanning any number of internal batches remains pending and cannot be paid until its full supplied source has been processed and reconciled. Interleaved source documents are supported; no adjacency assumption is used. A run-ownership marker prevents another incomplete import's evidence being prematurely certified.

The progress panel above the Purchases table shows processed/total entries, documents, lines, duplicates and errors; it explicitly shows final reconciliation separately. Leave the page open for polling. Reloading/returning in the same session resumes the saved run. A failed request pauses with **გაგრძელება**. A per-run filesystem lock prevents simultaneous step execution. If ERP commit succeeds but the checkpoint request is interrupted, the same step safely replays already committed rows as duplicates. Re-uploading the same unchanged full-year file is also safe: the already imported 970 valid rows skip, valid remaining rows continue, and old row-limit validation markers are replaced by current complete-source validation. V2 signatures retain the old three-decimal representation for quantities that fit it, so precision widening never re-keys historical hashes. This cannot prove that an externally truncated but syntactically complete export contains every original line when RS supplies no independent document total.

Every import returns a private JSONL report ID. All rejected/review rows include row number, source document/supplier, product/code when available, reason and raw evidence. Notifications retain only a preview; the progress panel and completion notification offer **ანგარიშის ჩამოტვირთვა** for the complete report. `/purchases/import-reports/{uuid}` uses existing Purchase-create authorization; files are not public. SQL errors/stack traces are not included. Document validation and progress retain up to 20 reasons; the disk report retains every rejected/review row. Workspaces are bound to the creating Owner, checked on every request, and kept privately for resume/administrative retention alongside reports. Do not remove a pending workspace while it is in use.

The 1,000-row hard stop is gone. One CSV/XLSX upload runs to EOF without manually splitting it; the existing 10 MiB upload cap and any lower PHP/web limits still apply. Internal limits are request/transaction boundaries, not source-file rejection limits. The initial streaming preparation performs no ERP writes and is followed by short resumable processing requests. A previous 10,000-row synchronous trial motivated replacing the single long web request rather than simply raising its row ceiling.

Mixed synthetic stress fixture: **5,000 source rows / 441,258 bytes**, several suppliers, known/new products, a 600-line cross-boundary document, explicit cancellation/credit data, duplicate rows and five malformed rows. With 970 valid lines already present, the completed run imported **172 documents / 4,021 lines**, skipped **974 rows** and reported **5 errors**. A full unchanged retry imported zero and skipped **4,995 valid rows**. Measurements are in `storage/logs/rs-full-year-scale.json` (isolated SQLite, not a production PostgreSQL or browser timing promise). The final verified run took **31.096 s** over **14 processing steps**, maximum step **3.548 s**, peak PHP memory **122 MiB** in the combined test process. All financial tables and P&L were unchanged, including a populated unrelated Finance receipt.

`products` remains the retail catalog. Its normal list and existing Cashier sale selector show only `catalog_status=sellable`; name, selling price and active status remain the retail fields. The old resource route is retained. A separate review tab keeps ambiguous legacy records accessible for an owner's explicit decision.

`purchases` and `purchase_items` remain the analytical document/line tables. `purchase_products` is their supplier-scoped material identity and remembered Direction mapping, **not** another expense-category registry. Direction references the existing `expense_categories` rows with `classification_dimension=direction`.

## Purchase Analysis metrics

Navigation remains Direction → Product Group → Product → Purchase History, with a small inline Back control and breadcrumb. Period presets are 7 days, 1/3/6 months, 1 year, All (default), and Custom; month/year presets are inclusive through today using no-overflow calendar subtraction. Custom exposes From/To. Existing direction, group, product, supplier and payment filters combine as before.

Group rows show distinct document count, comparable-unit quantity, amount and latest purchase in the selected period. Product count/quantity/amount also use that period and the selected filters. Product latest purchase, days since latest purchase, and average interval use the product's full valid history, independently of period/supplier/payment filters. Interval is `(last distinct purchase date - first distinct purchase date) / (number of distinct purchase dates - 1)`, equivalent to the mean of successive date gaps. Multiple lines in a document count once for document count; separate same-day documents count separately but share one frequency date. Fewer than two dates yields no interval. Days since last purchase uses the application calendar date.

Eligibility is read-only and shared by every analysis query. Explicit `validation_errors`, canonical audit-only kinds, cancelled/deleted/invalid text statuses and explicit return/credit/correction text in retained source fields are excluded. New `pending_import` markers also exclude incomplete documents until EOF finalization. Missing raw payloads, missing/false `validated` markers and empty `source_rows` alone do NOT exclude legacy or unconfirmed purchases: those newer fields are accounting-confirmation evidence, not cancellation statuses. Deleted records naturally disappear through the document join. A payment reversal or Bank P&L exclusion alone is not RS cancellation. Unknown numeric enums are never interpreted as a verified status; new imports retain them for review. Signed legacy lines are not removed on sign alone.

### Historical compatibility and read-only diagnostics

The analytics refactor incorrectly made modern raw-source verification mandatory for all imported documents. In particular, `import_batch_id IS NOT NULL AND rs_import_payload IS NULL` removed otherwise normal old imports before any grouping occurred. Direct-only Direction filters also missed group defaults. This was an eligibility/mapping compatibility error, not a switch to import or confirmation dates.

`PurchaseAnalysisMapping` resolves without writes or display-name matching, in order: direct catalog ID; an unambiguous existing supplier + legacy retail-ID link on another line; exact supplier-scoped RS code; an existing RS identity alias; exact supplier item code; an existing supplier identity alias. Codes come from the line's existing source hash and retained raw cells. Alias hashes use the catalog's existing SHA-256 identity format. SQLite registers a pure connection-local hash function; no schema or records change. Ambiguous code/legacy links are not guessed. Effective Direction follows the direct product assignment, explicit group default, unambiguous existing group member Direction, then an exact shared legacy category slug/classification code. Numeric IDs from unrelated category tables are never equated. Group assignments come only from the resolved catalog product.

Optional alias-table and group-Direction capabilities are checked twice at most per request and cached in a scoped service. Older schemas omit those optional joins/columns and continue using existing IDs/codes and group-member classifications; analytics does not require applying a migration. Direct mappings use a SQL CASE fast path so PostgreSQL never parses raw source cells for them, regardless of join predicate reordering. Data retrieval remains one aggregate query, or count + data for pagination, in addition to the two bounded schema checks.

Unresolved spending remains in Direction/ungrouped/unclassified totals. An unresolved product bucket has no frequency or last-purchase metric and no product-history drill-down action. The classification editor continues targeting canonical catalog IDs, including resolved legacy history rows; original purchase-line links remain unchanged.

For a safe production check after deploying the code, run:

```sh
php artisan purchases:analysis-diagnose --from=2026-09-20 --until=2026-10-19
```

Omit dates to use the current 1-month range. On 19 October 2026 this is 20 September–19 October inclusive. Filtering uses `DATE(purchases.purchase_date)`, in the application's configured timezone (printed in the output), not import/creation/confirmation timestamps. The command reports document/line counts inside and outside the period, eligible/excluded documents, missing modern payloads, direct mappings, compatible legacy mappings, Direction-only legacy lines, and unresolved/unclassified lines. Mapping counts overlap where explicitly named (an unresolved product can still have a valid Direction). No names, raw payloads or credentials are printed.

On PostgreSQL the diagnostic runs inside `SET TRANSACTION READ ONLY` and always rolls back; SQLite uses `PRAGMA query_only` and restores connection state. It refuses unsupported drivers or an existing outer transaction. It does not mutate application data, infer classifications, post Finance records, or require a backfill. The corrected empty heading is **შესყიდვები ვერ მოიძებნა**, with **არჩეული პერიოდისა და ფილტრების მიხედვით შესყიდვები ვერ მოიძებნა.** as the description.

The configured local PostgreSQL database was inspected read-only for 20 September–19 October 2026: 42 RS documents/125 lines, 41 included documents/122 lines, one document/three lines excluded by explicit evidence, one missing raw payload, 41 true verification markers and one missing marker. All 122 eligible lines had direct catalog IDs; 120 lacked a resolved Direction. Optional alias/group-Direction schema was absent. Therefore local data did not reproduce production's fully empty result or its claimed classification coverage; these counts must not be presented as production counts. The diagnostic prints optional-schema and verification-marker breakdowns so the production environment can be compared safely. PostgreSQL statements have a 15-second timeout; the successful local diagnostic completed in about 1.5 seconds after adding the direct-mapping fast path.

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

`PurchaseImportService` recognizes those actual export headers and dates. It writes `suppliers`, `purchase_products`, `purchases` and `purchase_items`, exactly the models read by the RS UI. Document lookup reuses supplier + RS source document ID (waybill number fallback), with supplier locking, so a subsequent export can append new lines without creating a second document. The current safeguards reject missing stable document identifiers; file fingerprints are retained for audit, not used to invent new document identities. Existing line hashes and legacy-hash compatibility remain.

`ListPurchases` refreshes table records and reports documents, items, duplicates, unclassified items and invalid rows. The current safeguards and private complete error download are described above; older verification notes below describe the implementation at that date.

`tests/fixtures/rs-items-georgian.csv` is a sanitized fixture with the original export's column structure. `RsImportFlowTest` covers upload through the actual action, all three views, mapping, partial/repeated imports, invalid rows and financial isolation. The optional original-file check uses `RS_IMPORT_SAMPLE` and the isolated SQLite test database: **20 documents / 37 items / 0 rejected**, then **0 new / 37 duplicates**. The original CSV and local database were left unchanged.

## Classifying identical catalog products

Repeated RS lines normally share a supplier-scoped catalog product. Direction and
Product Group live on that product, not on historical purchase lines. Exact RS
codes take precedence, followed by explicit aliases and supplier codes; code-free
products use the normalized full name (case and whitespace only). Shades, sizes
and model numbers are retained. Suppliers remain separate.

Catalog and Uncategorized dropdown changes remain local until Save. If separate
legacy catalog records match exactly, Save opens an Owner confirmation with
“ყველას მინიჭება” and “მხოლოდ ამ პროდუქტს”. Applying to all creates compatibility
aliases using the existing alias table; it preserves legacy products and original
purchase-line links. Conflicting classifications or contradictory identity codes
block propagation and require Owner review. The saved preview is checked again
under row locks; concurrent changes cancel the batch.

Analysis resolves these aliases before classification filters, grouping, history
and full-history frequency calculations. Future imports reuse the canonical
product. No financial records, RS source values or historical amounts are changed.
No additional migration is required for this catalog change; the existing
purchase-product alias schema must already be installed.

## Historical RS revalidation (explicit Owner operation)

`purchases:revalidate-rs --import=<completed-import-uuid> --owner=<owner-id> --dry-run`
opens PostgreSQL in READ ONLY mode and the retained SQLite checkpoint in `mode=ro`.
It reports precision repairs, documents eligible for revalidation, unresolved
document errors and file-level errors. It never executes a new import. The full
original JSONL report is preserved, including obsolete review warnings.

After reviewing that output, use the exact returned fingerprint:

`purchases:revalidate-rs --import=<same-uuid> --owner=<owner-id> --apply --fingerprint=<dry-run-fingerprint>`

APPLY locks documents/items, rebuilds the plan and rejects a stale fingerprint.
All changes are one transaction. Any Finance posting, Bank match or Advance link
blocks that document. Original removed rows/raw evidence and previous validation
state are retained in the purchase payload; existing application logging records
the Owner, timestamp and repair after commit. Product classifications and all
financial tables are untouched. Repeating a fresh dry-run/apply yields no changes.
No new migration is required.

Precision compatibility requires a stable product identity, the same document,
unit, price, authoritative line amount and VAT, plus the exact predecessor v2 hash
generated by rounding only quantity to the old three-decimal storage scale.
Contrary retained raw quantity blocks matching. It is not a numeric proximity
search. When an obsolete rounded row and its precise replacement both exist, the
complete checkpoint must prove a single source occurrence; unexplained extra rows
or ambiguous assignments block the whole document. Future imports upgrade a proven
rounded row in place, preserving its ID and recording the previous state.

`ზედნადების ტიპი` / `Waybill Type` is mapped separately to `waybill_type`.
Transported, non-transported and sub-waybill labels describe waybill handling or
structure, not accounting purchase/credit type. `Document Type` remains a separate
field; transport labels placed in that field still require review. Unknown
waybill labels/enums remain blocked, and cancellation/credit semantics take
precedence. Raw headers/cells remain intact.

Rejected rows invalidate only an attributable supplier/document identity. Unknown
document attribution remains a file-level error in the checkpoint/report, without
poisoning unrelated documents. Historical revalidation checks the completed source
against every retained line before clearing only errors explained by that run.
Unverified negative legacy purchases remain stored but are excluded from ordinary
Purchase Analysis; a negative sign is not interpreted as cancellation.
