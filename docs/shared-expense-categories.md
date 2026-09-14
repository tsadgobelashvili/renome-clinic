# Shared expense categories and Bank rules

## Daily use

1. Open **Finance > Expense Categories** as Owner. Add or rename a category, expand it to add subcategories, and use the ordering controls as needed. Cashier, Finance cash expense forms and Bank assignments use these same records. The former Bank Categories URL also opens this manager. Used categories/subcategories can be deactivated, but cannot be deleted; unused records can be deleted. Renaming updates historical labels through their IDs.
2. In **Finance > Bank**, click a transaction's category, date or description. For an outgoing payment, choose **Category** and optionally **Subcategory**, then save. The second selector follows the first. Only active targets are available for new assignments; an existing inactive assignment remains readable. Open **Finance > Expenses > Category > Subcategory** to inspect the combined Cash and Bank rows. Categories with no subcategories open their rows directly.
3. Select **Remember for future transactions** while assigning the payment. The preview uses the original counterparty and the editable purpose keyword. An optional exact account constraint is available. A blank keyword requires explicit confirmation of a company-wide default. **Apply to existing uncategorized transactions** is optional and never changes manual assignments. New imports apply active rules automatically.
4. Test Krosi 2 by assigning its rent payment to **Rent**, selecting Remember and entering `rent`. Assign its utilities payment to **Utilities**, select Remember and enter `utilities`. These create separate rules for the same company. A future Krosi 2 rent payment selects Rent; its utilities payment selects Utilities. With equally specific conflicting rules, a description containing both keywords stays Uncategorized. Use the exact phrases appearing in the actual BOG descriptions, including Georgian text when appropriate. No demonstration rules or transactions were inserted into the real database.

The adjacent **Categorization rules** page lists company, keyword, category, subcategory and active state. Rules can be edited, disabled or deleted. Manually changing an automatically assigned transaction changes only that transaction. Select **Update saved rule** explicitly to change future matching too. Deleting/disabling a rule retains previous transaction assignments.

## Matching and accounting

- Priority: normalized exact company plus purpose keyword, then company default, then description-only keyword. An optional exact account constraint increases specificity within its level. Equally specific rules with different category/subcategory targets leave the transaction Uncategorized.
- Matching trims/collapses whitespace, ignores case and tolerates anchored LLC / Georgian company prefixes. It does not use fuzzy company similarity. Keywords are literal substrings. Original imported text is unchanged.
- Safe BOG defaults remain first: COM outflows are Bank fees, PBS is a cash deposit, and verified card-settlement TRN credits are settlements. Expense rules apply to outflows.
- Uncategorized outflows remain visible in Finance Expenses under Uncategorized. Known transfers, settlements and excluded movements remain outside expenses. Already recorded in ERP and Legacy exclusions remain effective. Categorization never creates Payments or cash ledger entries, and imported credits never add ERP revenue.

## Data and performance

Two migrations add shared category/subcategory foreign keys to Bank transactions and rules, transaction rule provenance, and company/keyword/account rule fields. Legacy rule fields become optional. Existing expense assignments migrate to shared IDs without changing transaction dates, amounts, directions, descriptions or imported source data. Internal Bank movement codes remain for accounting compatibility; they are not a second user-managed expense taxonomy.

Restrictive foreign keys and model guards protect used targets. The compatibility migration's down method deliberately does not restore legacy NOT NULL constraints: newly created shared rules have no legacy Bank-category target.

The Bank page adds two fixed dimension reads for shared categories/subcategories, not a query per transaction. Import classification loads categories and rules in two reads per import and performs matching in memory against that context. Backfills use bounded chunks and grouped updates. Finance totals and category/subcategory breakdowns use SQL aggregation; transaction rows load only after selection. No persistent cache or new financial aggregation in PHP was added.

Regression coverage: `tests/Feature/SharedBankExpenseCategoriesTest.php`, plus BankAccounting, BankModule, ExpenseCategories and FinanceOverview tests. The migration was applied locally after a private backup; all 82 existing Bank transactions retained their pre-migration fields.
