# Expense category hierarchy

The existing `expense_categories` registry holds both levels. Top-level records
(`classification_dimension = direction`) are Categories. Child records
(`classification_dimension = type`) have a `parent_id` referencing their Category.
The existing financial `expense_direction_id` and `expense_type_id` columns remain
in use, so no parallel registry or new financial postings are introduced.

Settings shows expandable Categories with child create/edit/activate/delete actions.
Used records are deactivated instead of deleted. Unused records can be deleted;
foreign keys protect history. Used children cannot be moved to another Category.
Cashier, Finance, Israeli expenses, direct expenses and Bank use dependent selectors.
Changing Category clears Subcategory. Service/model validation rejects mismatched
pairs. Existing inactive selections remain readable for historical edits.

## Migration and historical data

`2026_09_17_200000_parent_expense_types_to_categories` adds `parent_id` and
`legacy_type_id` to the registry. Each used Category/Type pair gets its own child,
retaining the previous name, active state, ordering and original type reference.
Only `expense_type_id` is changed on existing financial records and Bank rules.
Amounts, currency, dates, original category/subcategory links and timestamps remain
unchanged. Incomplete classifications remain intact and appear under Needs review
for the missing level. Original global type rows remain as historical references,
not selectable children in expense entry forms or a second Settings list.

The migration also creates a small editable initial set of children. It does not
import transactions, delete history, duplicate expenses, or post Finance entries.
Rollback requires an explicit data-preservation plan rather than dropping history.

## Rules and automatic classification

Bank rules remember both IDs and only apply while both are active and the child
still belongs to that Category. Deleted, inactive and mismatched targets are skipped.
Existing system-generated salary classification retains its department inference;
commissions use General / Bank fee. Internal acquiring/transfer fee calculations
and accounting inclusion rules are unchanged.

## Reports

Category → Subcategory and Subcategory name → Category use the same monetary rows.
Reverse grouping combines identically named children across Categories. Stable numeric
representative IDs are used for report navigation; display names remain editable.
Date, source and currency filters are unchanged. Subcategory report filters include
all children sharing the selected name. Registry lookups are request-scoped;
financial aggregation remains in SQL and detail rows stay lazy-loaded.

## Verification

Focused tests cover Settings CRUD, dependent forms, invalid pairs, inactive rules,
legacy mapping idempotency, unchanged monetary rows/totals, reverse grouping, Bank
expense idempotency, cash expense details and payroll classification. For the local
migration, compare pre/post record counts and original columns excluding only the
remapped `expense_type_id`, plus P&L totals per currency.
