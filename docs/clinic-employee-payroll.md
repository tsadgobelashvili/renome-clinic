# Clinic employee net salary and required funding

For regular (non-technician) employees with a Clinic Fixed Net payroll setting, enter Net. The adjacent read-only Required Amount / საჭირო თანხა updates as Net or payment method changes. Bank uses the formula below. Cash uses Required Amount = Net, without the additional payroll planning tax/pension gross-up. Net 800 requires 1040.82 by Bank or 800.00 by Cash. Manual deduction, employer-cost and tax-configuration controls are hidden for this calculation. Israeli settings and existing non-net salary models retain their previous behavior.

The bank-payment calculation is the supplied business rule, not a change to doctor compensation:

- Taxable salary = Net / 0.80.
- Gross = Taxable salary / 0.98.
- Income tax = Taxable salary - Net.
- Employee pension = Gross * 0.02.
- Employer pension = Gross * 0.02.
- Required Amount = Gross + employer pension, calculated before rounding.

Monetary output is rounded to two decimals. For bank payments, Net 1000 requires 1301.02; 1300 requires 1691.33; 1500 requires 1951.53. Do not add independently rounded gross and pension fields to reconstruct the requirement: that can differ by one cent. The net remains the employee's payout.

Salaries > Employees shows Net and Required Amount separately. Open an employee row for the configured rule, payday, payment method and tax breakdown. Employee profiles and payroll previews also display Required Amount. The shared Clinic cycle below replaces the former employee-only dated cards.

## Shared Clinic payroll cycle

One card above Doctors / Employees shows the same total on either tab. The first date is the next 1st or 16th, including today. Once finalized, the date advances past that finalized date: 16 September -> 1 October -> 16 October, even when approved early. If time has advanced further, the next current calendar cycle is shown.

Doctors contribute their existing Clinic payable amount for work performed through today. The original per-visit formulas, category rates, discount rules, direct costs and owner split calculations remain unchanged. Owner counterpart shares automatically fixed by the existing settlement service are included in the approval total too. Future-dated visits are excluded.

Employees contribute their rounded Required Amount (cash always uses Net; bank-paid legacy non-net models use calculated gross plus employer cost). Their profile's monthly payday remains authoritative. Unfinalized paydays due by this cycle are included, including overdue paydays; future paydays and missing paydays are excluded. A shared cycle never changes the stored payday. For days outside 1/16, the next shared cycle on or after that payday handles the salary. The latest active setting effective for the employee's payroll period is used. Fixed salaries are for the scheduled period; variable salaries use performed work through the earlier of payday and today. After finalization, that employee advances one month from the snapshotted payday, even if approval happened early. Laboratory technicians and Israeli settings are excluded.

Click the card to review doctors, employees, their separate totals and total required, by currency without conversion. Owners confirm with `Payroll-ის დაფიქსირება ყველასთვის`; administrators can review. The review is revalidated before saving. Changed or already-finalized reviews are rejected and must be reopened.

Finalization is one database transaction. `clinic_payroll_cycles` stores the approved JSON snapshot, payroll date, cutoff timestamp, approver and finalized timestamp, with a unique date preventing duplicates. Nullable `clinic_payroll_cycle_id` foreign keys link doctor settlements and employee entries. Existing doctor salary fixing creates item snapshots and excludes those items from subsequent calculations. Employee entries preserve Net, Required Amount, settings, payment method and payday. Post-fix item and amount checks roll back the entire batch if the persisted result differs from approval. Finalized cycle snapshots cannot be edited or deleted through the model, and all confirmed Clinic doctor settlements cannot be individually undone. New snapshots and payroll cash expenses also reject edits and deletion through their models.

Cash finalization means payment from Clinic physical cash. Both individual and combined workflows call the same EmployeePayrollService / SalarySettlementService finalizers, which invoke ClinicPayrollCashPosting inside the payroll transaction. Cash employee outflow equals Net; doctor outflow equals the unchanged finalized doctor share. Doctor profiles now have a Clinic-only Bank/Cash salary method (existing doctors default to Bank), visible in individual and combined reviews. In a combined cycle, owner counterpart settlements use the recipient's own method. Individual Clinic owner finalization pays only the selected owner and leaves the counterpart share pending for that recipient's individual or subsequent combined payroll; it never silently pays the other owner.

ClinicPayrollCashPosting locks the payroll record and Cashier day, checks the existing Clinic cash balance, and calls FinanceManager to create one Salary expense. FinanceManager creates its Cashier mirror; Current Cash and Cash Outflow use that same ledger, and P&L counts the Finance expense once. Closed-day and insufficient-funds checks roll back the entire payroll, including any earlier postings in the batch. No Patient Payment is created and no Israeli funds are consumed.

Unique finance_transactions.payroll_entry_id and the existing unique salary_settlement_id are stable idempotency references. The existing unique Cashier/Finance link prevents duplicate mirrors. Retrying an already finalized payroll is rejected; replaying an existing cash posting is a no-op. Historical records are not backfilled or charged automatically. New cash employee entries have payout_status=paid; all-cash cycles have payment_status=paid. Bank entries and mixed cycles remain pending for bank payment. Bank payroll never changes Current Cash.

After finalization the card immediately advances. Fixed doctor item IDs stay excluded even if their visits are later edited; new visits and new items added on the same day remain eligible for the next cycle. Ordinary zero-payable doctor items are not fixed prematurely; they become payable when payment arrives. Explicit full-discount salary decisions retain their existing behavior. Employee configuration edits do not rewrite approved snapshots. The last finalized snapshot can be reopened from the card.

Card data uses batched compact doctor inputs and SQL employee-history selection; full visit reports load only when the review opens. No persistent cache is added. Tests verify that card query count does not grow with the staff roster, and that compact and reviewed totals agree.
