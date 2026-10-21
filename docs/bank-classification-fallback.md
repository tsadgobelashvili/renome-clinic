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
manual/rule/system records and current reliable rule matches. It clears only the
analytical fields covered by independent evidence, and records classification
metadata `repair`. Timestamps, notes, import/API source, amounts, dates, vendor and
bank details remain byte-for-byte intact. Legacy links require their own evidence.
Save the JSON output alongside the evidence as the repair audit.

The follow-up below supersedes the original repair's limitations and field list.
The original implementation blocked uncategorized rows when their assigned type
was the only expense-recognition fact. It did not repair historical data during
development, and it did not search legacy Category/Subcategory pairs.

## Initial validation (before follow-up)

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

## Follow-up investigation: production still displays the pair

The previous change prevented future null-code inference. It **did not clear any
historical assignment, change valid vendor rules, or deploy code**. Import deduplication
also preserves existing rows. Tests now demonstrate that a historically persisted
pair still displays under the previous fix even though replaying classification
for the same row today returns no classification. The previous local count did not
prove that the production screenshot was fixed.

Fresh database-enforced read-only diagnostics found **43 Bank rows, 10 outflows,
0 stored or Finance-projected Sterilization/Cleaning pairs**. There is no local
4,000 or 2,440 GEL row. Consequently, the specific production screenshot's origin
cannot be established from this local database. Historical persistence, saved-rule
classification, another deployed release, and a production-only projection/default
remain possibilities until an affected production trace is available.

The local 5 GEL example was traced end-to-end:

| Field | Local value |
| --- | --- |
| Bank ID / posting date | 23 / 2026-09-28 |
| Vendor / amount | შპს ოლსაიდს / 5.00 GEL |
| Direction ID / Type ID | 16 / 38 |
| Legacy Category ID / Subcategory ID | 14 / NULL |
| Saved vendor-rule ID / current matching rule | NULL / NULL |
| Stored classification source | `default` (system bank-fee operation) |
| Stored and resolved Finance Direction | საერთო |
| Stored and resolved Finance classification | ბანკის საკომისიო |

`BankTransaction -> BankClassificationService -> AccountingLedger::bankFields /
dimensionEntries -> EntryDetails -> Finance details` agrees for this row. Nothing
in this trace resolves to Sterilization/Cleaning. This is not claimed to be one of
the production screenshot's transactions merely because its amount is 5 GEL.

### Reusable read-only trace

```sh
php artisan bank:diagnose-classification
php artisan bank:diagnose-classification --transaction=23
php artisan bank:diagnose-classification --amount=4000 --amount=5 --amount=2440
php artisan bank:diagnose-classification --date=YYYY-MM-DD
```

On PostgreSQL this runs inside a REPEATABLE READ, READ ONLY transaction. It reports
all four stored classification IDs and labels, stored provenance and rule record,
the currently matching reliable rule, classification if imported today, shared
Bank-category links, actual Finance detail and analytical projections, confirmed
RS matches, timestamps, selected registry rows (including codes and parent IDs),
the active null-code lookup results, and the whole-row snapshot hash. Registry
metadata also exposes whether a semantic default record has been renamed.
Unknown historical origin
is explicitly reported as unproven, never relabeled as a confirmed fallback. The
suspect count includes both stored modern pairs and pairs displayed via legacy
links/projections. Date and transaction filters select posting facts, while the
repair command's date filters still select creation dates.

### Updated repair behavior

```sh
php artisan bank:repair-classification --dry-run
php artisan bank:repair-classification --dry-run --evidence=reviewed-evidence.json
```

DRY RUN now lists current labels and all stored IDs, reason, exact proposed fields,
Unclassified result, and whether expense recognition depends on this repair. It
also finds legacy-only Category/Subcategory pairs. To clear legacy links, the
independent reviewed evidence must additionally contain both legacy fields as NULL
in `before`; proof for newer dimensions alone never authorizes clearing legacy
links. With no independent historical evidence the candidate remains protected.
Manual, vendor-rule and legitimate system classifications remain protected.

The APPLY interface is unchanged, with **no APPLY run on application data**. It
now rejects any stale or missing evidence target before writing. A successful
proven repair clears only the approved analytical IDs and sets the existing
classification metadata `classification_source` to `repair`. The Bank import/API
`source` stays unchanged. Other row fields, timestamps and payment history remain
unchanged. The JSON audit includes the former IDs, proof reference and fingerprint.

The `repair` marker retains the expense recognition that existed before clearing
the bad type, including old uncategorized debits. Explicit transfer/exclusion
treatments still win. It suppresses re-inference and historical/shared-category
display fallback for the repaired row; its expenses remain under Unclassified.
Later explicit Owner classification or a reliable vendor-rule application can
replace that marker normally. In-transaction comparisons reject changes to P&L
or Bank/Cash movement totals. The tests compare the remaining transaction fields
and verify the original amounts, expenses, profit and movement totals exactly.

The final read-only local P&L remains GEL expenses **9505.04**, profit **-8805.04**;
USD expenses **0**, profit **45.00**. No existing application data was modified.

### Follow-up validation

- Nine focused fallback/trace/repair tests passed, **131 assertions**. The four
  unrelated rows use 4000.00, 5.00, 2440.00 and 5.00 GEL on the same date.
- Bank/BOG import, accounting, matching, vendor-rule, Finance Expense, Finance
  analytics, original-record and Cash reconciliation regression run: **202 tests,
  200 passed, 2 errors, 1637 assertions**. The two errors are the previously
  baselined `BankModuleTest` assumption that grouped rows have `getAttributes()`
  and `BogTransactionsPageTest` mocked query missing `rs_summary.status`.
- Final focused fallback and shared Bank vendor-rule rerun: **21 passed,
  307 assertions**. PHP syntax, Pint and `git diff --check` passed.
- No application-data APPLY, migrations, reset/reseed, commit, push or deployment.
