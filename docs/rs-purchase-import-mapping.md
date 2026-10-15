# RS purchase mapping investigation — 15 October 2026

## Verified source and affected records

Read-only PostgreSQL inspection identified purchase **33**, date **2026-10-08**, document/source ID **აქტიური**, supplier **ტრანსპორტირებით**, total **60,700.00 GEL**. Its item IDs are 77 and 79. Neither it nor the related genuine purchase 34 has a Finance cash posting, Bank match or advance confirmation. No application records were modified during investigation or tests.

The original export is `C:\Users\x\Downloads\report (7).csv`, SHA-256 `c72edb9db7a0dc037f822607223b0fc0d44f3ec241780548a495a906593c9a3a`. This is an Excel/CSV upload path, not an RS API integration. Replaying only the original parser's pure hashing helpers reproduces both database source-row hashes exactly:

- Item 77: `v2:449592cf4e5dea28d02fad5f129ac955e3d2d4855e45b80840ab18aba58f1`.
- Item 79: `v2:1217b1515bebfe184f07533c08fd331204e940f6b6caa24aeb2f2bb4706ec`.

CSV logical records 65/66 and 68/69 split two descriptions across **unquoted newlines**. Each prefix has only code and description; its continuation begins with an empty cell followed by the unit. Treating the continuation as an independent row shifts every following column left.

## Raw source values

| RS header | First affected line | Second affected line | Correct target |
|---|---|---|---|
| საქონლის კოდი | TM033B-4 | SH2622 | PurchaseProduct.rs_product_code |
| საქონლის დასახელება | სილიკონი - FLESIL (400Gx2; 50mlX2; ) + newline | ხალათი ერთჯერადი + newline | PurchaseItem.item_name / supplier product name |
| ზომის ერთეული | ნაკრები | ცალი | PurchaseItem.unit |
| რაოდ. | 5 | 200 | PurchaseItem.quantity |
| ერთეულის ფასი | 110 | 1 | PurchaseItem.unit_price |
| საქონლის ფასი | 550 | 200 | PurchaseItem.line_total (authoritative line amount) |
| დაბეგვრა | ჩვეულებრივი | ჩვეულებრივი | Retained tax-treatment text; **not** a VAT amount |
| ზედნადების ნომერი | 1013032128 | 1013032128 | Purchase.document_number / source_document_id |
| გამყიდველი | (404505068) შპს ოლსაიდს | same | Supplier |

No numeric VAT amount or separate document grand total is present in this export. No separate package/count fields are supplied. `400Gx2; 50mlX2` is part of the silicone description, not quantity or a monetary multiplier.

The complete genuine document has seven authoritative line amounts: **550 + 240 + 200 + 800 + 800 + 450 + 12 = 3,052 GEL**. This is the sum of source line amounts, not an independently provided RS document-total field. Purchase 34 currently contains only the five intact lines totaling 2,302 GEL; the two broken lines were misgrouped into purchase 33.

All seven source lines use the same document, supplier and date above. Their original purchasing fields are:

| საქონლის კოდი | საქონლის დასახელება | რაოდ. | ზომის ერთეული | ერთეულის ფასი | საქონლის ფასი | დაბეგვრა |
|---|---|---:|---|---:|---:|---|
| TM033B-4 | სილიკონი - FLESIL (400Gx2; 50mlX2; ) | 5 | ნაკრები | 110 | 550 | ჩვეულებრივი |
| 0022 | სამედიცინო ნიღაბი | 3000 | ცალი | 0.08 | 240 | ჩვეულებრივი |
| SH2622 | ხალათი ერთჯერადი | 200 | ცალი | 1 | 200 | ჩვეულებრივი |
| 702 | ხელთათმანი ლატექსის, ტალკის გარეშე, ზომა M, No. 100 | 80 | კოლოფი | 10 | 800 | ჩვეულებრივი |
| 701 | ხელთათმანი ლატექსის, ტალკის გარეშე, ზომა S, No. 100 | 80 | კოლოფი | 10 | 800 | ჩვეულებრივი |
| 726 | ხელთათმანი , ნიტრილი , შავი CITY MEDICAL, ტალკის გარეშე, ზომა S, No.100 | 50 | ცალი | 9 | 450 | ჩვეულებრივი |
| SH0606 | შპრიცი ერთჯერადი, ინსულინის, 1 მლ, 30Gx1/2" | 100 | ცალი | 0.12 | 12 | დაუბეგრავი |

Descriptions in this table omit trailing newline characters for readability. There are no numeric VAT amounts for any of these lines.

## Why the application produced 60,700 GEL

| Field | Broken first continuation | Broken second continuation |
|---|---|---|
| Product | ნაკრები (actually unit) | ცალი (actually unit) |
| Quantity | 110 (actually unit price) | 1 (actually unit price) |
| Unit | 5 (actually quantity) | 200 (actually quantity) |
| Unit price | 550 (actually line amount) | 200 (actually line amount) |
| Imported total cell | ჩვეულებრივი (actually tax treatment) | ჩვეულებრივი (actually tax treatment) |
| Fallback calculated amount | 110 × 550 = 60,500 | 1 × 200 = 200 |

`PurchaseImportService::number()` previously replaced the non-numeric total with `quantity × unit_price`. Both continuations also mapped status **აქტიური** as document number and waybill type **ტრანსპორტირებით** as supplier, grouping them into the same phantom document. `PurchaseItem` retained those supplied calculated totals; `Purchase::refreshTotal()` summed them to 60,700. The form displayed the stored result. Product codes did not become quantities; unit prices did. Tax-treatment text was confused with line amount, not a numeric VAT amount.

## Implemented safeguards

- Only the exact two-cell code/description prefix plus empty-cell continuation with the expected column count is joined. Other malformed structures fail validation. Properly quoted multiline CSV and indexed Excel cells retain their normal handling.
- Present but invalid monetary fields fail; no non-numeric value is replaced by a manufactured amount. Numeric source line amounts remain authoritative. Calculation is used only when a line amount is absent and valid quantity/price are available. Negative return rows are rejected for review rather than clamped into positive purchases.
- `purchases.rs_import_payload` retains headers, source cells, original fragments, file hash, sheet, record number, mapped values and an explicit source document total if supplied. Tax treatment and other unmapped source fields remain available there.
- No document is marked validated until file processing completes. Invalid rows block their own document; structural errors without a reliable identity block affected imports conservatively. Stored rows, source line amounts and explicit document totals are checked before cash, Bank, advance or Finance-manager confirmation. An explicit document-total difference greater than **0.01 GEL** is rejected. No independent document total is invented when the export lacks one.
- Imported RS financial fields cannot be recalculated through ordinary form edits; classification remains editable. Manual purchase behavior is unchanged.
- Import holds the purchase lock before adding lines. Confirmed cash/Bank/advance documents cannot receive new imported lines, source rewrites or source refreshes. Identical retries leave confirmed history unchanged.
- The Owner-only **RS წყაროდან განახლება** action appears on the RS purchase edit page when retained source exists and there is no accounting history. It atomically restores that document from its retained source, preserves item identities, validates reconciliation and creates no accounting movements. Both cash history (including reversed postings), Bank matches and advance confirmations block it.

## Existing affected document and deployment

The additive migration `2026_10_15_180000_preserve_rs_purchase_source_values.php` adds only a nullable JSON column. It contains no data repair and was not applied to application data during implementation. Historical uploads were deleted after processing and no raw payload was retained, so purchase 33 has no stored source eligible for refresh. It is **not** automatically repaired, merged or deleted. New confirmation of historical imported records without retained evidence is blocked until verified source is supplied. The current 60,700 GEL document will therefore fail confirmation after deployment rather than create an expense.

A reviewed re-import of the original export can attach source evidence to intact, unconfirmed lines and import the two correct lines under genuine document 1013032128. It does not rewrite confirmed records or guess a cross-document merge for phantom record 33. That record remains preserved and blocked pending explicit review.

Tests use anonymized realistic fixtures and SQLite `:memory:` only. The optional `RS_MAPPING_SAMPLE` test reads the private original file into the in-memory database; it verifies the genuine seven-line 3,052 GEL document and absence of the phantom status-number document. The original export also contains one separate negative return row, which is rejected for review without blocking this correctly identified document.
