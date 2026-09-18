# Scale readiness and import safety — 18 September 2026

## Scope and evidence

This is a code/schema audit plus narrowly scoped import hardening, not a production load-test certification. Code was reviewed against the requested 20–50k patients, 100k visits and 200k payments/bank transactions. The **local PostgreSQL index catalog** was inspected in a read-only transaction; production schema, production query plans and production latency were not measured. Tests use isolated in-memory SQLite and fake HTTP, with cached application configuration bypassed. No application data was reset, no migration was added/run, and no financial, salary or RS allocation formula was changed.

## A. Top scale risks before real data entry

| Priority / scale classification | Exact location | Finding and consequence | Narrow next step |
| --- | --- | --- | --- |
| High — definite growth in work at larger scale | `app/Services/Bank/BogBankSyncService.php::sync()` | The pending-staging anti-join still considers historical BOG staging for the configured account/currency on every sync. `lazyById(250)` bounds hydrated pending rows; it does **not** make the historical SQL check incremental. Fresh IDs are excluded with a list of up to 10,000 parameters. Current no-op fixture: six queries, zero historical models hydrated, but history still participates in SQL. | Measure the anti-join with production-sized data; design indexed publication/pending tracking that also preserves CLI imports, strict conflict detection and import rollback recovery. Do not just filter on operation date: old unpublished records would be missed. |
| High — definite unbounded PHP workload at larger scale | `app/Filament/Pages/FinanceReports.php::doctorStatistics()` (approximately lines 342–430) | Loads every visit in the selected range with doctor and treatment items, then groups in PHP. “All” can hydrate 100k visits plus their items. Other SQL aggregates on this page do not eliminate this load. | Preserve revenue apportionment/rounding with parity fixtures, then move compatible aggregates to SQL and bound detail retrieval. |
| High — definite unbounded PHP workload at larger scale | `app/Services/EmployeeSalaryService.php::pending()` | Loads **all active settlement items** and all Zirconia case-group identities before loading eligible works; settled work is excluded in PHP. Employee payout UI calls pending repeatedly for options, defaults, totals and remaining values. | Restrict settlement/group existence checks to candidate work in SQL while preserving cross-employee role protection and same-case Zirconia precedence. Reuse one calculation during a request only after parity tests. |
| High — definite unbounded history at larger scale | `app/Services/DoctorSalaryHistory.php::forDoctor()`; `app/Filament/Resources/Employees/Pages/ViewEmployee.php::performedWorks()` / `payrollHistoryAction()`; `resources/views/filament/resources/employees/salary-history.blade.php` | Doctor history loads every settlement and nested items, visits, patients, allocations and owner-share source history. Technician profile renders all performed work; employee history modals load all history. Eager loading prevents simple N+1 but does not bound memory or HTML. | Page history periods and lazy-load their details. Preserve the grouping of legacy settlement fragments. |
| High safety limit; medium-volume synchronous latency | `app/Services/BogBusinessApiService.php::statement()`; `app/Services/PurchaseImportService.php::import()` | API statement JSON is fully decoded; UI requests at most 10,000 records. RS parsing streams but writes synchronously in the upload request. Large imports can exceed request timeouts. The capped-response checkpoint gap is now fail-closed. | Validate permitted import sizes and expected throughput before a backlog import; implement verified date windows/API pagination separately if the BOG cap is reachable. |
| Medium — likely issue at medium/large matched-RS history | `app/Services/Bank/BankReport.php::query()`; `app/Services/Bank/BankPurchaseMatching.php::summaryQuery()` / `allocations()` | Bank table requests RS status on every page. Joined derived queries aggregate matched purchase totals and product allocations without an explicit restriction to the current page's bank IDs. The outer 25-row pagination does not guarantee those aggregates only touch 25 transactions. Actual plan/predicate pushdown must be measured. | Compare plans for date-filtered and all-history requests; restrict aggregate input without changing allocation semantics if plans confirm broad work. |
| Medium — likely issue at medium scale | `app/Models/Patient.php::scopeOrderByClinicDebt()` / `scopeWhereHasClinicDebt()` | Correlated visits/payment sums are indexed and do not hydrate full tables, but sorting/filtering debt may evaluate many patients before pagination. Substring/transliteration search also need not use ordinary B-tree name indexes. | Measure first with realistic 50k patient / 100k visit fixtures. Do not replace debt semantics or add search extensions speculatively. |
| Medium — growth in SQL work, bounded PHP | `app/Services/FinanceUsdUsageService.php::balances()` / `cashBalances()` / `applyMovementTotals()`; `app/Support/CashboxManager.php::physicalCashSnapshot()` | SQL grouped sums return few rows, but Israeli balances and some movement totals read cumulative history. Clinic physical cash uses the applicable opening/cutover. These are current-liquidity calculations: date-truncating them would change balances. | Measure SQL plans; only introduce audited opening checkpoints or rollups in a separate financially verified change. |

Small doctor/staff/category lists remain reasonable at the current intended roster size. The catalog navigation badge (`ProductMaterialResource::getNavigationBadge()`) executes a review count when rendered; this is the separate sellable catalog, not all RS products. Treat it as lower priority than the proven history loads.

## B. Pagination / unbounded-query findings

| Area | Current boundary | Assessment |
| --- | --- | --- |
| Patients index | Filament pagination (default 10; available 5/10/25/50), eager relationships and SQL debt columns | 150-patient fixture verified 150 total with at most 50 hydrated table records. Debt sorting/filtering still requires database work before paging. |
| Visits index / patient visit history | Explicit 10/25/50; index defaults to 10 | Main list is bounded. New-visit patient search is async, limited to 50; manipulation search is bounded. |
| Payments / patient payment entry | Cashier/visit tables are paginated or scoped to one day/visit; Finance payment history takes 200 per source | Not all payment presentations are pageable. `ViewPatient::outstandingVisitOptions()` loads the patient's entire visit/payment/item history before filtering debt in PHP; `Patient::getFinancialSummariesByCurrency()` has a similar per-patient fallback. Long-lived patient records are a medium-scale risk. |
| Doctors / Employees | Filament paginated indexes; small staff roster used in payroll | No restored `Employee::findOrFail()` loop in clinic finalization. History exceptions are listed in A. |
| Operational Lab / External Orders | Filament pagination; External Orders 10/25/50 | Eager case relations are page-bounded. External clinic distinct-name dropdown is unbounded in number of distinct names. |
| Bank | 25 transactions/page; history 10/page only when opened | No whole transaction collection for the table. SQL fee/RS aggregates can still be expensive; category registries are small. |
| BOG staging | No second large UI list: legacy page redirects to Bank | Fresh response bounded by API cap, pending staging hydrated lazily in 250-row batches; historical SQL scan remains. |
| RS documents / purchased items / uncategorized | Filament paginated lists; uncategorized is a filter of the item query | 150-document/item fixture verified pagination. Supplier relationship filter uses Filament's 50-option limit; it is not an unrestricted supplier-table preload. Selected product labels reuse loaded data; product search limits 50. |
| RS edit form | All items of **one document** in a repeater | Large individual documents can produce large Livewire payloads. This is not all purchase history, but it has no per-document item limit. |
| Product mappings | Supplier/code/name identity lookup; unique identity hash | No full catalog load during import. Missing product-relation index is listed in C. |
| Clinic payroll preview | Clinic visits chunked at 200 in `DoctorCompensationCalculator::payableSummaries()` | Memory bounded for clinic visits, but all unsettled history is processed. Finalization locks ordered staff/settings rows; larger roster/concurrent payroll increases lock duration. |
| Israeli/Lab payroll | `IsraeliLabSalaryItems::eligibleForDoctors()` loads eligible works and builds group-key lists; `LabSalaryService::eligibleItems()` loads period works | Date/eligibility scoped, but no count bound and large `whereIn` group sets are possible with an old unpaid backlog. Keep precedence/formulas unchanged during optimization. |
| Salary history | Doctor/employee history unbounded; `LabSalaries::history()` limited to 50 | A hard 50 limit is memory-safe but is not navigation to older records. |
| Statistics | Dynamics and full-discount summaries aggregate in SQL; full-discount visit details are lazy/paginated | Doctor statistics remains the major PHP aggregate exception. `FinanceReports::breakdownDescriptions()` is lazy but fetches all matching descriptions before reducing display samples. `breakdownDetails()` loads cash-out salary detail models for a full range; consultation-not-started drilldown is unbounded. |
| Finance history | Accounting ledger detail pages are paginated; older `Finance` history slices take 200 per source | Bounded slices can omit older matching rows without paging. `Finance::overviewMovementRecords()` / `exchangeExpenseExplanations()` also load the selected-range movement/expense collections; “All” is unbounded. |

`whereDate()` is not automatically an index bug here: `visits.visit_date`, `payments.payment_date` and `lab_cases.case_date` are declared as **date** columns. For timestamp columns, inspect the actual PostgreSQL expression/plan before recommending half-open timestamp comparisons or an expression index.

## C. Index opportunities

The following were verified in the **local PostgreSQL catalog**, not inferred only from migrations:

- Patients: first/last-name indexes, Latin-name composite, group/phone indexes, unique patient number and personal-ID hash.
- Visits: `(patient_id, visit_date)`, `(doctor_id, visit_date)`, `(visit_date, id)`, `(cancelled_at, visit_date)`.
- Payments: `(visit_id, currency)`, `(currency, payment_date)`, `(payment_date, created_at)`. Payment splits: `(payment_id, payment_method)` and method/currency date indexes.
- Lab cases: case date, `(doctor_id, case_date)`, `(patient_id, case_date)`, source/status/material. Main works: `(lab_case_id, material)`.
- Payroll entries: employee/finalized date and unique employee/source/period; salary settlements: doctor/period and doctor/settled date.
- Bank ledger: unique `deduplication_key`; operation ID/type/reference/date indexes and `(currency, direction, transaction_date)`.
- BOG staging: unique `entry_id` and primary key only. Sync state primary key is `(account_number, currency)`.
- Purchases: `(supplier_id, purchase_date)`, date, document number, source, nonunique source-document ID. Items: unique `source_row_hash`, **purchase-leading `purchase_items_purchase_lookup_idx` already exists**, plus `(product_id, purchase_id)`.
- Purchase products: unique `identity_key`, normalized-name index. Suppliers: unique exact name, nonunique normalized name and tax-ID index.
- Bank purchase matches: unique bank/purchase pair, purchase-leading index. Finance ledgers have date/type/source indexes and unique payroll/reversal-link constraints.

Candidates supported by code, **not added**:

1. `purchase_items(purchase_product_id, purchase_id)` — The product-to-item relation uses the new purchase product FK. The old `(product_id, purchase_id)` index does not cover it. Confirm reverse lookup/join plans actually benefit before adding; direction assignment itself only updates the product record.
2. `purchase_products(supplier_id, name)` and an appropriate direction-leading index — supplier-scoped product options and uncategorized/direction filters. Confirm selectivity before choosing both.
3. `lab_main_works(technician_id, lab_case_id)` — technician history/pending work queries; local main-work indexes lack a technician-leading index. Review analogous additional-work indexes before adding another.
4. BOG `(account_number, currency, id)` — helps account-scoped pending traversal, **does not solve historical anti-join growth**. Prefer choosing this together with the future pending/publication design.
5. Bank `(account_identifier, currency, transaction_date, id)` — selected-account/date table paths, if plans show the existing currency/direction/date index is insufficient. Fee-pair reference predicates need a separate measured plan before adding a compound reference index.
6. Source/category/direction/type foreign-key indexes for ledger/report filters — choose only high-selectivity missing paths after `EXPLAIN (ANALYZE, BUFFERS)` on a safe replica/staging copy. Do not add every single-column FK/status index by default.

Separate **integrity** concerns: `suppliers.normalized_name` and `(purchases.source, supplier_id, source_document_id)` are not unique. Do not add unique constraints before auditing/backfilling existing identities and resolving collisions. PostgreSQL does not automatically create an index for every referencing FK.

## D. RS duplicate/idempotency status

### Verified / hardened

- Current destination is `purchases` + `purchase_items`, with supplier-scoped `purchase_products` mappings. No parallel import destination was added.
- Existing unique `source_row_hash` protects exact repeats. Document resolution uses source `rs`, supplier and source-document identity; supplier rows are locked during bounded batches.
- Exact repeated fixtures and overlapping old-period imports skip existing lines and add only new lines. Partial invalid-row imports can be retried; successful prior rows remain skipped.
- **Fixed:** hashes include raw decimal/unit formatting, so `2` versus `2.000` / `10` versus `10.00` produced different hashes. After the normal hash fast path, existing documents now check the same resolved product and persisted quantity/unit/price/total/VAT. A transaction-local set also handles equivalent rows in the same new-document batch. Old hashes/data remain untouched.
- This fallback is document/product-scoped and uses the existing purchase-leading index. It adds an existence query for a changed-hash row in an existing document, not for an exact duplicate or normal first import. The batch set is bounded by the 100-row transaction and cleared after rollback.
- All-duplicate imports now show **success**, with zero imported and the skipped count; invalid/no-usable-data imports remain warnings. Notifications already report documents, items, duplicates and failed rows and show up to five error descriptions.
- Import still streams via OpenSpout; transactions hold at most 100 input rows for one supplier; row savepoints isolate failures; totals refresh once per affected document per batch. Mapping and selected-label behavior is retained.
- Existing isolation tests cover no Finance/Bank/Cashier/payment writes from RS import. Explicit cash posting and Bank matching remain separate workflows.

### Remaining limits — do not claim universal duplicate safety

1. Switching export identity fields can change the document key: RS document ID is preferred, otherwise document number, otherwise file hash plus date. A first export with number only and a later export with an ID can create a second document. No-ID exports from different overlapping files cannot reliably establish document identity. Preserve stable identifiers/export format for rollout; add ambiguous-identity rejection or a reviewed identity-alias strategy before mixing formats.
2. Supplier identity is normalized name, not a canonical RS taxpayer identifier. Existing-supplier locking serializes normal imports, but simultaneous creation of different exact names that normalize identically is not protected by a normalized-name unique constraint. Serial uploads avoid that race; a proper constraint requires a collision audit.
3. Missing dates default to today in `date()`. Repeating undated data on another day changes the hash. Numeric parsing also substitutes defaults for unsupported formats. Require dated, validated RS exports operationally; reject ambiguous rows in a separately tested parser-hardening change rather than silently guessing.
4. A changed amount/quantity is a new row hash; there is no stable source line ID/upsert correction workflow. Exact repeated identical lines can conversely collapse into one line. This pass retains the existing content-dedup semantics; it cannot distinguish a genuinely repeated identical line from a repeated export without a source line identity.
5. The new normalized fallback covers current `purchase_product_id` records. Legacy `product_id` rows retain the existing exact legacy-hash protection; differently formatted legacy exports are not universally reconciled.
6. Row error messages are accumulated for the full import, although the UI displays only five. A largely invalid large file grows memory and returns mostly unusable work. Exception strings may include DB details; do not treat the current summary as a structured reject-file facility.

## E. BOG checkpoint / idempotency status

- Production Bank button uses `BogBankSyncService::sync()`; `bog_sync_states.last_successful_sync_at` is keyed by normalized account/currency.
- No checkpoint: today minus six days through today (**seven inclusive calendar days**).
- With checkpoint: start of the calendar day **one day before last successful sync**, through today's date. App timezone is used consistently. API request enables current-day data (`includeToday`) and includes the requested date endpoints.
- Example: checkpoint 18 September 23:59, retry 21 September 00:01 → 17–21 September. Boundary test confirms today is included. The checkpoint records request start, not a later completion timestamp; overlapping the next sync captures later same-day entries.
- Staging, publication and checkpoint update share a DB transaction. API failure occurs before writes; publication conflict rolls back staging/publication/checkpoint; normalization errors prevent checkpoint advancement. A valid duplicate-only response is successful. A monotonic locked update prevents an older concurrent completion moving the checkpoint backwards.
- Staging unique `entry_id` plus batched `insertOrIgnore()`; bank ledger unique `deduplication_key` plus strict operation-ID date/direction/amount/currency conflict checking. Duplicate protection was not weakened; manual classification is not overwritten by a repeat.
- **Fixed:** if the current-day response reaches the requested 10,000-row cap, sync fails before import/checkpoint even when the API omits total-count metadata. Existing advertised-total truncation detection remains. Conservative failure at exactly 10,000 can reject a complete response; this is intentional rather than silently losing the rest.
- This guard **does not implement pagination or backlog recovery**. The current button has no smaller-window option. A verified windowed sync/recovery workflow is needed before importing a capped range; running the legacy CLI alone does not make the full UI range smaller.
- The CLI `app/Console/Commands/BogSync.php` is a separate existing local-only staging diagnostic: its no-date fallback is yesterday/today, without the Bank successful-checkpoint workflow. Do not schedule it as a substitute for the production button. It was not repurposed because doing so would change its publication semantics.
- No-op fixture with 301 already published historical staging rows hydrates **zero** historical records in six queries, but the anti-join still examines historical staging at SQL level. Therefore the requested “no historical staging scan” property is **not fully met**. Removing that check without a durable pending strategy would break retry/rollback recovery.
- A one-day overlap does not guarantee discovery of transactions newly posted/backdated earlier than that window. That requires verified BOG posting semantics or a controlled reconciliation/backfill policy; no claim of universal late-posting coverage is made.

## F. Fixes implemented

| File | Change |
| --- | --- |
| `app/Services/PurchaseImportService.php` | Formatting-independent existing-item fallback and bounded within-batch duplicate check; preserve source hashes and monetary calculation. |
| `app/Filament/Resources/Purchases/Pages/ListPurchases.php` | Duplicate-only import is success, not warning; counters retained. |
| `app/Services/BogBusinessApiService.php` | Fail closed at the current-day statement cap before successful checkpoint advancement. |
| `tests/Feature/ScaleImportSafetyTest.php` | Six focused retry, formatting, cap, date-boundary and pagination tests. |
| `tests/Feature/RsImportFlowTest.php` | Assert duplicate-only notification status and exact counters. |
| `docs/scale-readiness-import-safety.md` | This audit. |

No migrations, indexes, persistent cache, infrastructure, scheduling, accounting-rule changes or commits.

## G. Tests / results

Before fixes, the initial five new tests had **3 passes, 2 failures** (86 assertions): RS formatting repeat duplicated a line, and capped BOG response advanced rather than failing. Both now pass. An additional same-batch formatting test also passes.

Focused combined run: **157 tests; 156 passed, 1 skipped, 0 failed; 1,421 assertions**. Files:

`ScaleImportSafetyTest`, `PerformancePhaseTwoTest`, `RsImportFlowTest`, `RsPurchaseSeparationTest`, `PurchasesTest`, `RsCashPaymentTest`, `BankPurchaseMatchingTest`, `BogSyncTest`, `BogTransactionsPageTest`, `BogTestStatementTest`, `BankAccountingTest`, `BankFinanceCriticalFixesTest`.

Skipped: optional original RS export test (`RS_IMPORT_SAMPLE` not supplied). Synthetic fixtures include real-export Georgian header structure without private source data. The full suite was not run.

Current-pass query budgets **unchanged** from the committed Phase 2 implementation:

| Fixture | Before this pass | After this pass |
| --- | ---: | ---: |
| RS 60 items / 5 products / 3 documents | 265 queries, 3 total refreshes | 265 queries, 3 total refreshes |
| Same RS import repeated | 67 queries | 67 queries |
| BOG no-op with 301 published historical rows | 6 queries, 0 historical rows hydrated | 6 queries, 0 historical rows hydrated |
| Finance statistics income / expense / cash-out | 23 / 23 / 26 | 23 / 23 / 26 |
| Finance overview | 9 | 9 |

150-record patient/document/item fixtures verify bounded table hydration and correct pagination totals. These query counts are regression evidence, **not** 200k-row PostgreSQL timing measurements. Financial-isolation, cash posting idempotency, matching and classification regressions pass. Pint and `git diff --check` pass.

## H. Before Monday / real-data rollout

1. Apply these small fixes through the normal reviewed deployment and run the focused checks against a safe production-like PostgreSQL copy. Verify backups/restore and production index parity; local schema is not proof of deployed indexes.
2. Use a consistent dated RS line-item export with stable supplier and document identifiers. Do one representative repeat/overlap dry-run on the isolated copy and compare document/item counts/totals. Avoid concurrent RS uploads until supplier/document identity uniqueness is hardened. **Do not certify arbitrary mixed-format historical reimports safe yet.**
3. Review missing/changed identifiers, identical repeated product lines and legacy product rows before bulk historical RS onboarding. Reject/resolve ambiguous identities rather than guess or merge monetary history.
4. Confirm normal BOG overlap windows stay below 10,000 records. If not, complete and verify windowed recovery before syncing that backlog. Keep the old successful checkpoint on all failures; use the Bank workflow rather than the staging CLI for production.
5. Measure BOG pending anti-join and Bank RS-summary plans with production-sized copies before committing to frequent sync at 200k rows. The no-op-history-scan requirement remains open.
6. If importing multi-year Lab/payroll/visit history at launch, first bound technician/doctor history and the global pending-work loads, and address doctor “All” statistics. Starting with small current operational datasets permits these volume-specific changes later; it does not make them scale-safe.

## I. Safe to defer until volume grows

- Index candidates after measured selectivity/plans; no speculative index batch now.
- Patient debt-sort/search optimization while real response times remain acceptable.
- Large single-document repeater pagination, external clinic option search, and navigation badge tuning while these datasets remain small.
- Cumulative-balance rollups only after actual aggregate timings justify them and reconciliation invariants are specified.
- Cursor pagination / asynchronous import infrastructure only after measured request-duration limits justify a separate task.

This diagnostic/hardening pass is complete. It fixes the demonstrated formatting-repeat and capped-response checkpoint failures, but **does not certify universal RS identity safety or 200k-row no-op sync performance**. Those remaining limitations are explicit rollout decisions, not hidden behind passing small-fixture tests.
