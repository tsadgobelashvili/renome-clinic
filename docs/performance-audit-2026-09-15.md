# Performance audit — 15 September 2026

Status: static/schema audit complete; runtime measurement, regression verification and migration execution blocked by automatic approval review. This is not a completed performance sign-off.

## Evidence collected

Read-only inspection of the actual local PostgreSQL schema found 85 visits, 75 payments, 87 payment splits, 93 patients, 82 bank transactions, 3 import batches and 12 lab cases. No patient values were exported, and no records were changed.

The previous pass's changes remain intact: payroll reuses its locked employee collection; Finance USD balances use grouped SQL aggregates. Previously verified service-only counts were Israeli balance 9 → 2, Clinic balance 13 → 4, Israeli cash 9 → 2, Clinic cash adjustments 3 → 1 (excluding Cashier internals). These are not page query counts.

## Targeted additive indexes

Prepared migration: 2026_09_15_120000_add_patient_visit_payment_lookup_indexes.php. Not applied locally.

| Table / index | Recurring query evidence |
|---|---|
| visits(patient_id, visit_date) / visits_patient_date_lookup_idx | Patient history and eager-loading by patient; Patient::scopeOrderByLatestVisit uses MAX(visit_date) per patient; clinic debt subqueries correlate visits.patient_id to patients.id. The live schema has doctor/date and date/id indexes, but no patient-leading index. |
| payments(visit_id, currency) / payments_visit_currency_lookup_idx | Visit paid balances, Patient::visitOutstandingSql and DoctorCompensationCalculator::payableSummaries correlate payments.visit_id to visits.id and compare currencies. The live schema has currency/date and date/created_at indexes, but no visit-leading index. |

PostgreSQL uses CREATE/DROP INDEX CONCURRENTLY outside a migration transaction. Other drivers use Laravel's schema builder. Equivalent exact-column indexes are skipped; rollback only drops this migration's names. Interrupted invalid concurrent builds are detected rather than silently accepted on retry.

No existing migrations were edited. Indexes change access paths, not returned values or query counts.

## Reviewed and intentionally unchanged

- Bank operation_id already has an index. Ingestion batches deduplication_key lookups, backed by a unique index.
- Bank dates, currency/direction/date and operation_type already have indexes. No redundant indexes or description/text indexes added.
- Salary settlement item visit_treatment_case_id and lab_main_work_id are already unique/indexed, covering finalized-item exclusion.
- Payroll entries already have employee/source/period uniqueness and employee/finalized_at indexes; settings already have employee/source and source/active/effective_from coverage.
- Lab has case_date, doctor/date, patient/date, source and status indexes.
- Patient exact identifier lookup already has its HMAC unique index. Existing name/phone indexes retained; wildcard name searches do not justify another ordinary B-tree.
- Previously suggested low-cardinality source/type/currency indexes for Finance were not added without representative EXPLAIN evidence. Current local tables are very small.
- No additional N+1 was confirmed through static inspection. Lists generally eager-load their displayed relationships. Runtime scaling must still be checked.

## Remaining potentially expensive paths

- Dashboard tomography summary currently hydrates all matching payments and splits before summing. This is not an N+1 (relations are eager loaded), but memory grows with daily volume.
- Patient debt filtering/sorting uses correlated SQL subqueries. The two proposed indexes support their joins; sorting many patients by a computed balance can still be expensive.
- Payroll review/finalization performs deliberate per-person calculations and locks. Do not remove transaction-safety queries based on counts alone.
- Doctor report drill-down loads detailed work collections; full-discount and other overview aggregates already use SQL. Broad all-history reports still require work proportional to history.
- Bank balance account discovery/window ranking and substring description searches may become costly with much larger imports. Existing 82-row data cannot substantiate production-scale latency claims.

## Pending page measurements

WorkflowPerformanceAuditTest seeds isolated SQLite fixtures at sizes 1 and 10, measuring component mount and a Livewire refresh separately. It records SQL counts, cumulative database milliseconds, repeated query shapes and the slowest SQL shape, without bindings, in ignored storage/app/performance-pages-{size}.json.

| Workflow | Query count / runtime N+1 status |
|---|---|
| Dashboard | Pending execution |
| Visits | Pending execution |
| Patients | Pending execution |
| Finance Overview | Pending execution |
| Bank | Pending execution |
| Payroll | Pending execution |
| Statistics overview (FinanceReports) | Pending execution |
| Laboratory | Pending execution |
| Partner Patients | Pending execution |

SQLite fixture timing must not be presented as production PostgreSQL latency. After tests run, inspect PostgreSQL EXPLAIN (ANALYZE, BUFFERS) on representative read queries before claiming index speedups.

## Verification still required

Automatic approval review rejected the test command three times because its review model was at capacity, including after SQLite :memory: / RefreshDatabase isolation was verified. No workaround was used. Tests and migrations did not execute during this audit.

Run the workflow and index tests, then the relevant Dashboard, Visits, Patients, Finance, Bank, payroll, reports and Laboratory suites. If successful, run php artisan migrate and repeat schema/EXPLAIN checks. No business logic or UI changes were introduced in this pass.
