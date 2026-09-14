# Daily Finance

Finance now shows five primary cards: **Cash, Bank, Revenue, Expenses, Profit**.

- Cash and Bank are current liquidity, independent of the selected period and Clinic/Israeli source.
- Revenue, Expenses and Profit use the displayed date range and All / Clinic / Israeli source filter (default All). Presets: 7d (default), 1m, 3m, 1y, Custom. Currencies remain separate.
- Click Revenue for ERP receipts with Clinic / Israeli source, method and currency, patient, description and amount.
- Click Expenses for category totals, then subcategories and their Cash / Bank entries.
- Click Profit for Revenue − Expenses = Profit.
- Click Cash for the physical cash opening and paginated ledger history through today. Click Bank for account balances and the link to its separate history.

Inflow/Outflow cards, Total Available, explanatory paragraphs, settlement/status columns and the duplicate P&L navigation item have been removed from normal use. Finance transfer, USD usage and opening-balance actions are under **More**. Their existing behavior is retained.

## Accounting

Revenue comes from canonical ERP records: Clinic cash/card payment splits, Israeli payments, product sales and manually recorded business income. Imported Bank credits never add revenue, including categories previously labelled Other business income. Record any true additional business income once in ERP.

Expenses include Finance expenses, independent Israeli expenses, older Cashier expenses without a Finance mirror, classified and Uncategorized Bank expense outflows and Bank commissions. A linked Cashier/Finance expense counts once. Salary reversal postings reduce expenses rather than adding revenue. Cash expenses paid today still use Cashier; expenses paid from held/withdrawn cash still use Finance. No operating cash calculation was changed by this simplification.

Bank credits, own-account transfers, deposits and currency exchange never create profit. Existing Already recorded in ERP and Legacy flags still prevent duplicate or pre-cutover Bank expenses, but their controls are under **Additional details**. Raw Bank movement and operation-ID conflict protection remain intact.

Cash and Bank expenses use one managed ExpenseCategory / ExpenseSubcategory hierarchy. Finance > Expense Categories manages both. Bank details assign the shared IDs directly and can remember company/purpose rules. See [Shared categories and Bank rules](shared-expense-categories.md) for creation, matching priority, manual overrides and Krosi 2 testing. Internal movement types still distinguish transfers and settlements from expenses.

## Reported Bank balance

The Bank card uses the newest reliable dated, non-rolled-back statement balance for each account/currency. It does not add later raw transactions or derive a balance from ERP payments, costs or fees. It shows the statement date/time and its age. No live API balance is claimed.

A configured Bank opening is a labelled fallback only when no reliable statement exists for that account/currency. A statement always takes precedence. Cash opening/cutover support remains under More; no opening or cutover was configured during this work.

Overlapping statements are supported: known operation IDs with identical date/direction/amount/currency are skipped; new rows are added. A newer statement closing date updates the balance even if many/all rows are duplicates. Conflicting operation IDs still reject the import. An older statement imported later cannot replace a newer balance.

## Bank page and fees

Bank shows reported balance, Bank Expenses, Bank Fees, import/history and a compact transaction table. **Relevant** hides settlement rows by default, preserving expenses, fees, unclassified payments and operational transfers. **All transactions** restores the complete history. Switching visibility never changes financial totals. Fee expenses withheld from hidden settlement credits remain included in Finance's Bank fee category, where their individual expense rows can be opened.

BOG gross and commission metadata can come from spreadsheet columns or explicit same-currency description labels such as `თანხა:GEL 500; საკომისიო: GEL 10;`. Original descriptions and cells remain unchanged. Automatic withheld fees require a classified settlement and verified `gross − credited amount = fee`. Thus ERP revenue 500, Bank credit 490 and withheld fee 10 produces Revenue 500, Expenses 10, Profit 490.

Standalone COM debits take precedence over a corresponding fee annotation. Correlation is scoped to bank/account/currency and either a shared reference or, when a reference is missing, matching fee amount and transaction date. This is conservative duplicate protection, not ERP payment matching. Ambiguous annotations without gross/net proof still require explicit confirmation under Additional details. Distinct non-empty references are not matched merely because fees have equal amounts. A later overlapping import containing a corresponding COM automatically replaces the annotated contribution rather than adding it again.

`php artisan bank:refresh-commission-metadata --dry-run` previews older rows with missing metadata. Without `--dry-run`, it fills only missing gross/fee fields from original descriptions. It never changes movement amounts, dates, direction, categories, raw cells or operation identity. It is idempotent after values are populated.

For the retained 04–11 September statement, 54 rows received missing metadata. A private full Bank backup was taken first. All 82 transactions, their immutable movement facts, categories, import batches, patients, payments, Cashier and Finance records were verified unchanged. No data was deleted/reset and no commit was created.

Historical verification figures after the commission-metadata update, before Uncategorized expense inclusion:

- Reported Bank balance: **50,169.10 GEL**, statement dated **11 September 2026**.
- Full history: **82 rows**; Relevant: **28 rows**.
- Bank fees for 4–11 September: **357.96 GEL** = 40.00 standalone COM + 317.96 confirmed withheld commissions.
- Bank expenses in that period under the existing manual categories: **2,733.73 GEL**. These include Bank fees; do not add the Fees card again.

## Manual testing with existing mixed data

1. Open Finance. Check five cards and default 7d. Change dates/currency; Revenue/Expenses/Profit update, while current cash and reported Bank balance do not change with dates.
2. Open Revenue. Check Clinic/Israeli, Cash/Card and currency on ERP payment rows. Bank settlement descriptions must not appear here.
3. Open Expenses, then Bank fee or another category. Confirm Cash and Bank costs share category totals and each detail row identifies its source. Profit must equal Revenue minus Expenses per currency.
4. Open Bank with Custom **04.09.2026–11.09.2026**, GEL. Check the balance/date and 357.96 fee total. Relevant hides the 54 settlement rows; All transactions restores them. For withheld fee details, use Finance → Expenses → Bank fee.
5. Import the same original file: expected 0 new rows, 82 duplicates, 0 rejected. A later month-to-date export should add only new operation IDs and update the balance to its newer closing date. Do not edit the original workbook or delete previous batches to import overlaps.
6. Keep using Cashier for today's cash expenses and Finance for held-cash expenses. Inspect Expenses afterward to confirm each cost appears once.
7. The ERP data is test data and the Bank statement is real. Do not expect their overall balances/revenue to reconcile. The independent 500/490/10 scenario, cash/card/Israeli receipts, transfers, and overlap behavior are tested in isolated database fixtures.
8. Configure real opening balances only when the actual go-live date/amounts are decided. For Cash, enter GEL and USD on the same unused current/future date; omitted currencies start at zero. Use Custom from the cutover date for go-live performance; retain earlier history.

## Implementation and validation

- Finance page, `Concerns/HasFinanceOverview`, Finance overview/detail Blade views: five-card UI, source labels, lazy inline details, no movement aggregate on render.
- `AccountingLedger`: ERP-only revenue, source identity, shared categories and unmirrored Cashier expenses.
- `BankBalances`, `BankReport`, Bank page/views and shared `bank-balance-updated` view: direct statement balance, freshness, Relevant/All, expense totals and secondary technical controls.
- ExpenseCategories and the BankCategories compatibility route: one shared manager; ProfitLoss retains hidden duplicate report navigation.
- `BogCommission`, `BankTransactionData`, `BankAccounting`, `RefreshBogCommissionMetadata`: explicit metadata extraction and fee duplicate protection.
- English/Georgian translations; FinanceOverview, BankAccounting and BankModule regression tests; these guides.

The original five-card simplification added no dependency. The subsequent shared-category update adds two migrations; see the shared-category guide. Overview normally uses five fixed reporting reads, one fewer than before. Inline rows add one paginated read; Expenses adds one category aggregate, then one subcategory aggregate after selecting a category, and loads rows only after selection. Bank now adds two fixed shared category/subcategory dimension reads to its original six-query baseline. Commission correspondence uses SQL EXISTS inside the aggregate; there are no per-row application queries, persistent caches or PHP ledger summations.

## Current cash and business-source filter (14 September)

The Cash card previously selected only the latest Cashier day. An empty later day could therefore display zero while earlier ledger cash remained held; a closed day showed only its drawer carry amount. Local inspection reproduced this: the latest empty day displayed zero, while the existing physical ledger held 95,535.87 GEL and 553.04 USD.

`LiquidityReport` now calls `CashboxManager::physicalCashSnapshot`, also used by `physicalCashBalances` for existing Finance funding checks. It aggregates the physical cash ledger across days, using the latest effective explicit opening/cutover, or the initial legacy opening when no explicit opening exists. Subsequent day openings/carryovers are not counted again. Cash expenses and real withdrawals reduce the balance; card payments do not. Internal closing handovers stay excluded under the existing Cashier rule, and paired internal transfers cancel. Future transactions/openings do not enter the current balance. Viewing Finance never creates or closes a day.

The new source selector filters the same SQL ledger used for Revenue, Expenses, Profit, categories, subcategories and transaction details:

- Clinic: canonical patient cash/card payments, sales, and expenses assigned to Clinic through existing funding/cash-source fields or the Clinic Cashier ledger.
- Israeli: existing partner receipts and assigned Israeli expenses. Linked Finance/partner mirrors are counted once.
- Mixed salary postings: use stored clinic_cash_gel / israeli_cash_gel allocations; reversals subtract the matching share. An unallocated mixed posting is retained in All instead of guessing a split.
- All: includes both sources plus shared expenses without an assignment. Bank imports have no Clinic/Israeli field, so eligible Bank expenses remain in All only. General Finance expenses without source information follow the same rule. Categories alone do not imply a business source.

Bank settlement credits never become Revenue. Profit is always filtered Revenue minus filtered Expenses. Shared expenses mean All Expenses may exceed Clinic Expenses plus Israeli Expenses. The source selector tooltip explains this. Cash and Bank cards remain unchanged by date/source; the existing currency selector still controls which currency is displayed.

Manual local check: open Finance, compare Cash with the figures above, then select an older empty period and switch All / Clinic / Israeli. Cash must stay unchanged and Bank must retain its latest statement balance (50,169.10 GEL for the retained September statement). Use 01.09.2026 through 14.09.2026 for period data. Open Revenue and Expenses, expand categories/subcategories, and compare their amounts with the selected cards. Israeli amounts come from the existing partner flow; Bank costs appear under All. No local data was inserted, reset or deleted for verification.

Performance: physical cash uses two fixed reads with an explicit opening, or three without one, including a single currency-grouped SQL SUM. This replaces separate per-currency cash sums. Source filtering adds predicates/CASE expressions, not queries. Category and detail loading remain lazy, with no N+1 or persistent cache.
## Combined Clinic and Israeli current cash

Current Cash now adds `FinanceUsdUsageService::cashBalances('clinic')` and `cashBalances('israeli')` separately for GEL and USD. Clinic uses the Cashier physical ledger plus its existing Finance cash adjustments; Israeli uses partner cash receipts less cash expenses, with recorded exchanges, transfers, withdrawals and salary-cash adjustments. No conversion is performed by the card. Date and business-source filters still affect only performance.

The prior 95,535.87 GEL / 553.04 USD diagnostic figures above were Clinic-only and are no longer the expected combined card values. Cash details display both source balances; the existing paginated Cashier movement list is explicitly labelled Clinic. The balance services are reused unchanged rather than creating another Israeli calculation.

Israeli cash usage dropdown labels are now GEL, USD, and USD -> GEL exchange (existing Georgian exchange label preserved). The keys direct_gel, direct_usd, and exchange_usd_gel remain unchanged. No migration or record update is needed.
## Current Cash source selection (supersedes the source-independence behavior above)

All shows Clinic + Israeli current cash; Clinic and Israeli show only their respective existing balances. GEL and USD remain separate. The date filter never changes current cash. Bank stays combined under every source because no reliable Clinic/Israeli account allocation exists. There is no Total Available card in the current layout; the report returns null for that derived amount outside All mode. Cash detail amounts follow the selected source, and the Clinic-only movement list is not shown in Israeli mode. This selects existing balance results without adding queries or changing balance calculations.
## Cash Outflow

The sixth card, Cash Outflow / ნაღდის გასავალი, shows period cash-out legs in GEL and USD separately. Date, source and currency filters apply. Click for five groups (Expenses, Bank Deposit, Owner Withdrawal, Currency Exchange, Other), then select a group for paginated date/type/source/description/amount rows. No operation rows load on initial render.

Included: cash expenses posted through Cashier, Finance cash expenses with no Cashier/Israeli mirror, Israeli cash expenses and salary cash allocations, cash-to-bank transfers, owner withdrawals, exchange cash-out legs, and other Cashier withdrawals/transfers. Bank deposits are identified by structured cash-to-bank endpoints, not guessed from descriptions. Generic Cashier withdrawals remain Other. Card and bank-only payments are excluded. Linked Finance and Cashier records are not counted twice; mixed salaries use their posted cash shares. Unknown-source legacy cash expenses appear in All only. Internal day-closing handovers remain excluded by the existing Cashier rule.

Only actual expense postings also belong in Expenses/P&L. Deposits, withdrawals and exchanges do not become business costs merely because cash leaves. Expenses and Profit calculations are unchanged.

Current Cash now displays Opening + Cash inflows - Cash outflows = Current Cash for the selected source over its full balance history, independently of the report dates. Its equation uses the same Cashier opening/cutover and supplementary Israeli/Clinic cash legs used by the existing balance services. Existing held-cash Finance expenses can occur after money was withdrawn from the current cash pool: the period outflow report lists that expense, while the current-balance equation retains the original withdrawal treatment rather than spending that money twice. No balance logic or postings are changed.

CashOutflowReport uses SQL UNION ALL and SUM/GROUP BY, with EXISTS checks for mirrors. One aggregate supplies the new card. Group totals and individual rows load only when opened; the current-cash equation adds fixed aggregate reads only when Cash is opened. No persistent cache or per-row queries are added.