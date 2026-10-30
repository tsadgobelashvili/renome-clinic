# RS document deletion and unused catalog products

RS Product Catalog uses `purchase_products`, separate from retail `products`.
Previously, deleting a document cascaded its `purchase_items` only; it did not
evaluate the independent product catalog. This left catalog entries behind.

## Automatic cleanup

Owner deletion of an unaccounted RS document now runs in one transaction:

1. Lock the document and capture its direct and resolved canonical product IDs.
2. Reject documents with Finance postings (including reversals), Bank matches or
   advance settlements. Existing accounting history is never removed.
3. Delete the document and its purchase lines.
4. Check only the affected product identities. Delete a product only when
   `catalog_origin = rs`, `manually_retained = false` and it has no blockers.

Blockers include every incoming database foreign key (including Finance and
Israeli Finance), purchase history resolved by exact legacy codes or aliases,
references to an owned alias, and aliases needed by another catalog identity.
Classification alone is not a blocker: Direction and Product Group are outgoing
references. Only the product's assignment disappears; reusable entities remain.
Exclusive unused aliases are removed in the same transaction as their product.
Any failure rolls back the document, lines, products and aliases together.

New RS imports/recovery record RS origin. Manual catalog creation or explicit
manual resolution records manual intent; later RS reuse preserves it. Existing
rows have unknown origin and are retained automatically. Names, codes, dates and
classification are insufficient evidence to backfill provenance.

## Existing CAD/CAM orphans

After the schema migration and application update are deployed, an Owner can
open **RS → Product Catalog**, find each exact CAD/CAM product, choose
**პროდუქტის წაშლა**, and confirm. The server locks the current row and checks all
references again, even for historical products with unknown origin. A referenced
product stays in place and a Georgian notification explains the blocker. An
unused historical product and its exclusive unused aliases can be removed by
this deliberate individual action. There is no bulk cleanup or automatic sweep.

Deleted products disappear from catalog queries and product filters. Their
deleted purchase lines cannot contribute to Purchase Analysis quantity, amount,
last purchase, interval or history. Shared products retain valid purchase history
and their saved classifications.

## Audit and operational scope

Committed deletions use the existing structured application log mechanism:
`Unaccounted RS purchase deleted` includes the document snapshot, removed product
IDs/names/codes, retained reasons, Owner ID and timestamp. `Unused purchase catalog
product deleted` records the product and exclusive aliases, Owner and timestamp.
Audit callbacks run only after commit.

The provenance migration adds two columns and does not backfill existing records.
No existing application data is repaired or cleaned by installing this change.
No Finance, payments, Bank, cashier, cash, advances or ledger rows are updated.

## Verification

- RS deletion, catalog, identity/aliases, Purchase Analysis and purchase/Bank
  matching regressions: 186 passed, 1,317 assertions (including 15 new tests).
- Disposable PostgreSQL integration: 10 assertions passed, covering exclusive
  cleanup, shared history, preserved taxonomy and Finance reference protection.
- Finance regressions: 226 of 249 passed; 14 failures and 9 errors exactly match
  the stored baseline test names and assertion count. No new failing tests.
- Changed PHP syntax, Pint and `git diff --check` passed.

Only in-memory and new disposable databases were used. The application database
was not migrated or cleaned; no commit, push or deployment was performed.
