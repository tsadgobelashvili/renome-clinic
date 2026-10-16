# Owner recovery of unconfirmed RS purchases

## Read-only eligibility inspection

On 15 October 2026, purchase **#33** remained a malformed RS staging record: date 8 October, status text `აქტიური` as document number, supplier `ტრანსპორტირებით`, two lines totaling 60,700 GEL. Purchase **#34** was the genuine document `1013032128`, with five intact lines totaling 2,302 GEL.

PostgreSQL inspection ran inside `SET TRANSACTION READ ONLY`. Both purchases had zero Finance transactions (including reversals/history), zero Bank matches/RS allocations, zero advance entries and zero Cashier mirrors. The database's incoming foreign keys for purchases are `finance_transactions.purchase_id`, `bank_purchase_matches.purchase_id`, `employee_advance_entries.purchase_id` and purchase items. There is no independent purchase salary/payroll foreign key or confirmation/finalization flag; salary/payroll references through a Finance posting are blocked with that posting. No other incoming purchase/item finance references were present.

The two #33 products have no RS/supplier codes and no direction, type or group mappings. The five #34 products have exact RS codes; they also had no classifications at inspection. This makes both records structurally eligible **at inspection time**. Eligibility is checked again in preview and under database locks at confirmation. Existing application data was not modified during implementation or tests.

## Owner workflow after deployment

The purchase edit page header contains **RS ფაილიდან ხელახლა იმპორტი** (English locale: **Re-import from RS file**). It is Owner-only in the UI and service. Accounted RS records can show the action so a blocked attempt can identify the exact Finance, Cashier, Bank allocation or advance record IDs; they cannot be replaced.

To recover #33:

1. Open RS purchases and edit **#33**.
2. Choose **RS ფაილიდან ხელახლა იმპორტი**.
3. Upload the original `report (7).csv` using the fixed parser and enter **1013032128** as the RS document number. The application selects that document from the file; unrelated documents are not imported by recovery.
4. Continue to the preview. Confirm the parsed supplier/date, seven descriptions, codes, quantities, units, unit prices and authoritative line amounts. The preview shows **Current total 60,700 GEL** and **Re-imported total 3,052 GEL** only if those are the actual database/source values. No amount or document ID is hardcoded in the recovery service.
5. Review the explicit overlap warning: unaccounted **#34** will be consolidated into **#33**, with its five exactly matched item identities and product mappings retained. Its original header and lines are retained in #33's recovery history. An unmatched extra line or any accounting on #34 blocks this consolidation.
6. Tick the confirmation checkbox and click **ჩანაცვლების დადასტურება**. Until this step, the purchases, supplier/product catalog and accounting tables remain unchanged.
7. Review/classify the recovered #33 before using the existing cash, Bank or advance-confirmation workflow. The recovery itself creates no Finance expense, Cashier/Bank movement or advance confirmation.

## Source validation and duplicate handling

Ordinary imports and recovery share the same streaming strict parser. Recovery requires source-supplied line amounts, rejects malformed/ambiguous rows and duplicate line IDs, and checks consistent document identity, supplier and date. An explicit source document total must agree within 0.01 GEL. Without that field, the displayed total is explicitly the sum of authoritative source line amounts. The original example has no separate document-total field; its seven source amounts sum to 3,052 GEL.

Preview is read-only. The locked server preview fingerprints the original staging records, overlap items, classifications and source-file hash. Confirmation re-parses the file and rejects stale previews. The supplier and affected purchases are locked in a stable order, blockers are checked again, and line replacement, hash rebuilding, retained payload, total reconciliation and overlap consolidation happen in one transaction. Any error rolls back all changes. A recovery request UUID prevents repeated confirmations from duplicating lines or recovery records.

The existing global source-line hashes and ordinary duplicate rules are retained. After recovery, the normal importer recognizes the seven lines under #33 and skips them. It does not recreate #34. Other documents owning a source hash block recovery rather than having their lines taken silently.

## Classification and history

Existing supplier-scoped RS codes, supplier product codes or exact retained source hashes preserve reliable product associations, including direction, type and group. An ambiguous code blocks recovery. Names and units are not used to transfer classifications. An unmatched source line remains unclassified; name-only products receive a source-specific identity rather than inheriting a similarly named product's classifications. Old unmapped catalog records are preserved.

Matched overlap items keep their IDs. #33 keeps its purchase ID, creator and notes. Replaced staging lines and the consolidated staging document are snapshotted in `rs_import_payload.recovery_history`, together with user and timestamp. Existing application logging records a post-commit recovery event with old/new totals, document ID, absorbed staging IDs, source hash, user and timestamp. No separate audit framework or financial reversal is introduced.

## Deployment and scope

This workflow needs no additional migration beyond the previous nullable retained-source column migration, `2026_10_15_180000_preserve_rs_purchase_source_values.php`. That prerequisite must be deployed through the normal migration process. No migration or recovery was run against application data during implementation. The existing stored-source refresh, reconciliation guards, strict parsing and form recalculation protections remain in place.

Tests use SQLite `:memory:` only. They cover the #33-style phantom, the seven-line 3,052 source fixture, the #34 overlap, preview/confirmation, stable classifications, unmatched classifications, financial blockers, stale source/records, complete rollback, ordinary import deduplication and Owner authorization.
