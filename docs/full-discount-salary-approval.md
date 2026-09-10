# Item-level salary approval for 100% discounts

For exactly 100%-discount Clinic work using the ordinary doctor salary rule,
the calculator retains the original-value salary calculation, direct expense
allocation, category percentages, and rounding. That result is the **potential
doctor share**. Actual payout is zero unless the item's ID is explicitly
approved. Normal visits, partial discounts, Israeli salary, owner splits, and
CT/consultation eligibility retain their existing rules.

## Selection and finalization

The salary modal binds compact **ხელფასი გაიცეს / Pay doctor salary**
checkboxes to `approved_full_discount_item_ids`. The initial list is empty.
Each checkbox controls one manipulation; an unapproved item still shows its
potential amount. Changing doctor, source, or date range resets selection.

Both the preview and `SalarySettlementService::settle()` receive the selected
IDs. The calculator considers IDs only within its existing doctor/date/source/
cutoff/eligibility query. It recomputes amounts from loaded records, never from
client-provided amounts. Selection uses a lookup set, with no added queries.
Approving one item does not redistribute an unapproved item's potential share.

Finalization snapshots **all included eligible items**, including declined
ones. Declined items are therefore finalized decisions, not pending work that
will reappear in the next salary calculation. Existing exact-item cutoff and
same-day visit handling stay unchanged.

## Stored audit fields

`salary_settlement_items` now stores:

| Field | Meaning |
| --- | --- |
| `is_full_discount_snapshot` | Whether the visit had exactly 100% percentage discount |
| `potential_doctor_share_snapshot` | Calculated potential salary on that free work |
| `salary_approved` | `true` / `false` for the manual decision; `null` when inapplicable or historically unknown |
| `doctor_share_snapshot` | Existing field holding the actual finalized salary, zero for declined work |

The settlement's existing `created_by` and `settled_at` identify the actor and
finalization time. All new columns are nullable, and the migration does not
rewrite historical payouts or invent historical approvals. Potential values
and decisions remain available in the salary history even after doctor rates
change.

The 100% Discounts statistics report continues to sum only actual confirmed
salary snapshots. It labels declined work **Finalized — salary not paid**,
separately from unfinalized work and other confirmed zero-salary records.

## Files changed for this refinement

- `app/Services/DoctorCompensationCalculator.php`
- `app/Services/SalarySettlementService.php`
- `app/Models/SalarySettlementItem.php`
- `app/Filament/Actions/DoctorSalaryAction.php`
- `resources/views/filament/resources/doctors/salary-calculation-modal.blade.php`
- `resources/views/filament/resources/doctors/salary-history-records.blade.php`
- `app/Services/FullDiscountStatistics.php`
- `lang/en/discount-salary.php`, `lang/ka/discount-salary.php`
- `lang/en/discount-statistics.php`, `lang/ka/discount-statistics.php`
- `database/migrations/2026_09_10_120000_add_full_discount_salary_approval_snapshots.php`
- `tests/Feature/DoctorFullDiscountSalaryTest.php`
- `tests/Feature/FullDiscountStatisticsTest.php`
- `docs/full-discount-statistics.md`, this document

Focused tests cover the 130 GEL → 52 GEL potential example, default OFF,
independent approvals, persisted approved/declined amounts, unchanged normal
salary and exclusions, historical display, statistics, and equal query counts
with approval enabled or disabled.
