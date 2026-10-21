# Bank classification fallback investigation

`ExpenseDimensions::infer()` translates legacy labels into semantic codes. Unknown
labels produce `null`. Previously `ExpenseDimensions::id($dimension, null)` searched
the registry for the first row with a null `classification_code`. Custom categories
have null codes. Thus an unknown direction could resolve to a custom direction
(Sterilization), and an unknown type to a custom root type (Cleaning). `childForType()`
then resolved/created its child under that direction. Registry order determined the
accidental pair; this was not a vendor match or a product-catalog decision.

`BankTransaction::saving` calls `ExpenseDimensions::apply`, so these values could be
persisted during import and subsequent saves. The independent-dimensions migration
also calls `backfill()`, which used the same inference. The affected period starts
when this code and eligible null-code custom categories coexist; deployment and
category history are needed to establish production dates. Current registry order
alone cannot prove what it was historically.

The fix returns null immediately for null/blank codes. Explicit IDs, recognized
legacy semantic codes, vendor matching, manual classification, bank COM defaults,
and confirmed RS allocations retain their existing paths. Cash, Israeli and manual
Finance models share inference and therefore also benefit when a code is unknown.
The Bank editor already hydrated missing IDs as empty strings and had an empty
option; its empty option now consistently says `დასაზუსტებელია`. Bank rows display
classification provenance. Unknown historical provenance is reported honestly.

## Local read-only diagnostic (2026-10-21)

The database-enforced read-only transaction found 43 Bank rows, **0** outflow rows
with Sterilization / Cleaning. One other custom-category Bank row was explicitly
marked manual (Laboratory / Furniture) and was preserved. Local null-code custom categories were Furniture,
not the production pair. This local snapshot does not establish a production count.
P&L before any changes: GEL revenue 700.00, expenses 9505.04, profit -8805.04;
USD revenue 45.00, expenses 0.00, profit 45.00. No application records were changed.

## Owner repair procedure

Run from the application directory using its PHP runtime:

```sh
php artisan bank:repair-classification --dry-run
php artisan bank:repair-classification --dry-run --from=2026-09-17 --until=2026-10-21
```

The optional dates restrict **record creation dates**, not bank posting dates.
Output lists candidates, stored provenance, immutable snapshot hashes and proposed
actions. A suspicious pair is never sufficient evidence. With no evidence file,
all candidates are preserved. Manual, rule and system-source records are protected.

For a row proven by a historical audit/import trace to have received the null-code
fallback, an Owner/reviewer can prepare a JSON evidence manifest keyed by Bank ID:

```json
{
  "123": {
    "snapshot_sha256": "hash from the current dry run",
    "mechanism": "buggy_null_code_fallback",
    "reference": "path/identifier of reviewed historical audit or inference trace",
    "before": {"expense_direction_id": null, "expense_type_id": null}
  }
}
```

The reference and before-state must come from independent historical evidence;
do not manufacture them from the current row or assume absence of source is proof.
The command checks manifest structure and snapshot freshness, not the authenticity
of external evidence. If no such evidence exists, retain the record for review.
Use the same manifest to review the exact proposed changes:

```sh
php artisan bank:repair-classification --dry-run --evidence=reviewed-evidence.json
```

Only after Owner review, the available APPLY invocation is:

```sh
php artisan bank:repair-classification --apply --owner=OWNER_ID --evidence=reviewed-evidence.json
```

APPLY was **not run on application data** during this investigation. It requires
an Owner ID, locks evidence targets, rechecks each entire-row snapshot, preserves
manual/rule/system records and current reliable rule matches, and changes only
`expense_direction_id` and `expense_type_id` to null. Timestamps, legacy audit links,
notes, source, amounts, dates, vendor and bank details remain byte-for-byte intact.
Save the JSON output alongside the evidence as the repair audit.

Some old uncategorized rows qualify as expenses solely because of their assigned
type. These are deliberately **blocked**, even with fallback evidence, if clearing
would remove expense recognition. They need a separate Owner-reviewed accounting
decision; this repair will not silently change their bank treatment or totals.
Rows with independent expense recognition move into the Unclassified analytical
bucket. An in-transaction before/after comparison of all P&L totals by currency
rolls the entire repair back if Expense, Profit or Revenue would change.

## Validation

- Final focused Bank fallback / Finance original-record tests: **15 passed,
  136 assertions** (including 7 new fallback/repair tests).
- Cash reconciliation fixture regression: **5 passed, 54 assertions**.
- Product classification JavaScript tests: **8 passed**.
- Broad requested regression run: **611 tests**, 568 passed, 23 failures,
  18 errors, 2 skipped, 4610 assertions. Five errors identified the minimal Cash
  query fixture missing `classification_source`; the fixture was corrected and
  every affected test passed on rerun. The additional seventh fallback test was
  added afterward and passed in the final focused run.
- Baseline against original tracked files for all failing test files: **221 tests,
  185 passed, 23 failures, 13 errors**. After excluding the five corrected fixture
  errors, the failing test identities match the broad run exactly: **no additional
  failing test identities introduced by this change**. The broad suite is not green.
  Existing failures include obsolete Finance report/preset expectations, missing
  `applyDynamicsDatePreset`, Bank test assumptions about Eloquent versus grouped
  rows, a mocked Bank query without `rs_summary`, and an isolated migration fixture
  without its suppliers table. These unrelated application/test problems were
  left outside this fix.
- PHP syntax checks and Pint passed for changed PHP files. `git diff --check`
  passed. Persistent database diagnostics were SELECT-only, and final dry run
  confirmed the same P&L totals as the initial read-only snapshot.
