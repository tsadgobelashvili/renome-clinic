# Expense categories

Owners manage categories at Finance → Expense Categories (`/admin/expense-categories`). The navigation and controls have Georgian and English labels. Categories and subcategories support names, active state, sort order, editing and deletion.

## Storage and safety

- `expense_categories`: id, name, active, sort_order, timestamps.
- `expense_subcategories`: id, expense_category_id, name, active, sort_order, timestamps.
- Nullable, indexed foreign keys `expense_category_id` and `expense_subcategory_id` on finance_transactions, partner_finance_transactions and direct_expenses.
- Foreign keys restrict deletion. Model deletion also rejects referenced records. The management page deactivates used records when Delete is selected; unused categories and their unused children can be removed.
- Referenced subcategories cannot be moved to another parent. Saving expenses validates the parent/child pairing and active state. Existing inactive selections remain valid when editing history.
- Six editable initial categories: Surgery, Therapy, Orthopedics, Laboratory, Administrative and Other (stored with Georgian names). No fixed subcategory sets are generated.

Legacy accounting codes and historical rows are preserved. New manual financial expenses use a stable `managed_{id}` grouping key, resolved to the managed name. Reports still aggregate amounts in SQL. Existing system-generated salary and transfer operations retain their established accounting codes; automatic clinical salary mappings are deferred.

## Forms and performance

The shared Filament select schema is used by Finance expenses, Dashboard expenses, Cashbox expenses, USD direct/spend expenses, general Israeli expense operations, Visit direct expenses, and the Direct Expenses create/edit dialog. Category is required, subcategory optional, and category changes clear the subcategory. New expenses offer active choices; editing retains the current inactive choice.

Owner-only management checks apply to every mutation. Existing administrator access to expense entry is retained. Category lists use eager loading (two queries regardless of category count). Dependent choices query only the selected parent. Historical label dictionaries are loaded once per request only when needed. There is no persistent cache.

## Files added

- app/Models/ExpenseCategory.php
- app/Models/ExpenseSubcategory.php
- app/Models/Concerns/HasExpenseClassification.php
- app/Support/ExpenseCategoryForm.php
- app/Filament/Pages/ExpenseCategories.php
- database/migrations/2026_09_10_200000_create_expense_categories.php
- resources/views/filament/pages/expense-categories.blade.php
- lang/en/expense-categories.php
- lang/ka/expense-categories.php
- tests/Feature/ExpenseCategoriesTest.php
- docs/expense-categories.md

## Files updated

- app/Models/FinanceTransaction.php
- app/Models/PartnerFinanceTransaction.php
- app/Models/DirectExpense.php
- app/Filament/Pages/Finance.php
- app/Filament/Pages/FinanceReports.php
- app/Filament/Pages/Dashboard.php
- app/Filament/Pages/Cashbox.php
- app/Filament/Resources/Visits/Schemas/VisitForm.php
- app/Filament/Resources/DirectExpenses/DirectExpenseResource.php
- app/Filament/Resources/DirectExpenses/Pages/ListDirectExpenses.php
- app/Filament/Resources/DirectExpenses/Tables/DirectExpensesTable.php
- app/Filament/Resources/PartnerFinance/Pages/ListPartnerFinance.php
- app/Filament/Resources/PartnerFinance/Tables/PartnerFinanceTable.php
- app/Services/DirectExpenseService.php
- app/Services/FinanceUsdUsageService.php
- resources/views/filament/pages/cashbox.blade.php
- resources/views/filament/resources/direct-expenses/expense-editor.blade.php
- tests/Feature/PartnerFinanceTest.php (freeze the clock for a fixed-date fixture)
- tests/Feature/TreatmentCaseTest.php (compare the calendar date in date-filter state)
