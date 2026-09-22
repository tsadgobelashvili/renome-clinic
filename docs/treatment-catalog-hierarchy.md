# Treatment Catalog hierarchy

Deploy the code with:

```sh
php artisan migrate --force
```

Migration: `2026_09_23_120000_create_treatment_classification_tables`.

Catalog → **კატეგორიები** manages categories; **სტატისტიკის ჯგუფები** manages category-owned groups. Used records cannot be deleted. Renaming retains the record ID and historical classification. Move a manipulation by editing its category/group; a used group cannot be moved to a different category.

The existing `treatment_cases.category` and `statistics_group` columns remain the references. Their values are now IDs of `treatment_categories` and `treatment_statistics_groups`, respectively. Legacy string IDs are preserved where valid; new records use ULIDs. These are real record identifiers, not display labels. No parallel classification columns are introduced.

The migration copies existing categories and groups, including category/group pairs outside the old hard-coded defaults. Cross-category legacy groups receive distinct deterministic IDs while preserving their labels and assignments. No visit, payment, salary, or procedure mapping is rewritten. Existing unclassified categories stay unclassified.

Uncategorized procedure assignment uses Category → In group / Directly in category → optional Group. The inline + creates a group in the chosen category and selects its ID. Assigning creates/reuses that procedure's catalog item and retains the existing normalized-name mapping. It does not merge differently named procedures.

Statistics resolves current catalog relationships, including historical free-text procedures with saved mappings. Category and group labels are batch-loaded per request; grouped manipulation quantities are aggregated in SQL. Direct items remain directly under the category. Renames and later mappings apply to historical reports without editing visits. Existing amount/payment attribution rules remain unchanged.

Rollback restores original legacy group keys. Do not roll back after using newly created categories/groups without first exporting and reviewing those classifications: the older application cannot understand the new managed IDs.
