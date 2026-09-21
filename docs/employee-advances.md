# Employee advances (GEL)

Finance → თანამშრომლის ავანსები is owner-only. Issue an advance, then confirm purchases from RS → document → ავანსთან მიბმა, or use ხარჯის დამატება on the advance detail. The list uses SQL subquery totals; history is paginated at 25 entries.

## Accounting and funding

- **Cashbox:** posts one immutable `cash_withdrawal` in the existing Cashbox on the issue date. A return posts `cash_transfer_in`. Neither is revenue or expense.
- **Previous days / accumulated cash:** posts an `employee_advance` movement in the existing Clinic `partner_finance_transactions` ledger. This consumes/restores retained cash without posting to today's drawer. Closed-day posting is rejected.
- **Bank:** requires an existing GEL debit with the same amount/date. The debit stays in bank movements and is excluded from direct expense recognition; there is no synthetic bank transaction. Confirm a bank-funded return only after the real return occurred; the bank credit arrives through normal sync.
- **Other:** records the advance/return in the movement report without adjusting cash or bank balances.

`employee_advances` holds the issue and source. `employee_advance_entries` holds immutable RS confirmations, manual expenses and returns. Confirmed, returned, remaining and overspent amounts are derived from these entries, not cached financial totals. GEL is explicit; this feature does not convert or post USD.

An advance is not an expense. `AccountingLedger` recognizes each confirmation on its confirmation date. RS allocation reuses `PurchaseExpenseAllocation`, including uncategorized shares. Updating product mappings updates analysis without another deduction. Manual confirmations reuse the shared category/type validation and selector.

**Overspending interpretation:** a confirmed 1,100 GEL purchase against a 1,000 GEL advance is recognized once as 1,100 GEL, with 100 GEL owed to the employee. No extra money movement or second expense is created. This feature does not post reimbursements; do not record the same purchase again as a reimbursement expense.

## Safety

- Unique issue/manual request keys and row locks prevent repeated submissions. Each RS document has at most one advance settlement across all advances.
- Advances settle progressively through multiple RS documents and/or manual expenses. Each linked document is confirmed for its whole validated total.
- RS bank matching, direct cash payment and advance settlement are mutually exclusive, checked under document locks.
- Settled documents' financial lines cannot be changed or deleted. Their product classifications remain editable.
- Confirmed manual expenses and advance money movements cannot be silently edited/deleted. Returns are explicit, audited and require confirmation of the current remaining amount.
- Advance bank references use restrictive foreign keys; rolling back their import must not delete the linked bank debit.
- Services check active Owner authorization in addition to the resource policy.

## Salary advances

The optional **ხელფასის ავანსი** toggle stores `employee_advances.is_salary_advance` (default false). It uses the same issuance/return movements as purchase advances, but RS/manual expense settlement is rejected server-side.

Regular Employee payroll (individual and shared Clinic finalization) consumes available advances oldest date/ID first, in the payroll currency, capped at the employee's net salary. Each immutable `salary` advance entry references its `payroll_entry_id`; a unique advance/payroll pair prevents duplicate application. `payroll_entries.salary_advance_applied` preserves the deduction, while `net_amount` and tax/pension calculations remain unchanged. The remaining payment is net salary minus applied advances; a fully prepaid salary produces no new payment movement. Bank payroll remains pending for the remaining payment under the existing workflow; no bank debit is synthesized.

For payroll with applied advances, Finance reports the full finalized employee salary once and excludes its linked remainder payment from P&L only. The actual remainder movement still affects cash normally. Advance deduction entries are not additional expenses. Bank payments already recorded in ERP continue to use the existing exclusion/reconciliation workflow.

The separate Laboratory technician settlement/carry/reversal workflow is unchanged in this pass.

Apply the additive salary extension after the base migration:

```sh
php artisan migrate --path=database/migrations/2026_09_21_180000_add_salary_advances.php
```

Do not roll this extension back after salary deductions have been posted: retain the payroll links and deduction history.

## Deploy and verify

Run the normal reviewed migrations, or apply only this migration:

```sh
php artisan migrate --path=database/migrations/2026_09_21_160000_create_employee_advances.php
php artisan optimize:clear
```

Do not roll back this migration after posting real advances: its `down()` removes the new advance tables. Retain this financial history like other posted records.

Focused coverage: `tests/Feature/EmployeeAdvanceTest.php`, `RsCashPaymentTest.php`, `BankPurchaseMatchingTest.php`, `CashboxTest.php`, `FinanceOverviewTest.php`, `FinanceReportsTest.php`, `FinanceBalanceQueryTest.php`.
