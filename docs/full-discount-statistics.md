# 100% discount statistics

The **100% Discounts** tab is inside Reports / Statistics, alongside Finance,
Dynamics, and Doctors. It uses the existing FullDiscountStatistics Livewire page
as an embedded component and shares one content view with the original
`/admin/full-discount-statistics` route, which remains available for old links.
There is no separate sidebar entry. Access follows the existing owner-only
Analytics policy. The child mounts only while its tab is active: other tabs run
zero discount statistics queries. Activation loads the existing six aggregate
queries and doctor options; detailed rows remain lazy-loaded on demand.
The page performs reads only and does not modify Finance, visits, salary rules,
payments, cancellation, or historical discount reasons.

## Metrics and scope

- Only non-cancelled visits with `discount_type = percent` and
  `discount_value = 100` are included. Full amount-based write-offs and partial
  percentage discounts are not included.
- Filters: visit date range, 14 days / 1 month / 6 months / 1 year / all,
  source, doctor, category (Therapy / Surgery / Orthopedics / Other), and service
  currency. **All** removes both date bounds, including for historical visits.
  Discount reasons remain available through the collapsible reason breakdown,
  rather than as a toolbar filter. Other includes all categories outside the
  three named categories, matching the category summary.
- The four top KPIs are Patients, Visits, Free service value, and Doctor salary
  paid. The existing distinct-patient and monetary calculations are unchanged.
- The Doctor salary section below the KPIs shows Paid / Not paid visit and
  manipulation counts. Clicking either opens the existing scoped, paginated
  detail list with explicit Salary paid Yes/No and the more specific salary
  status. The detailed status breakdown is collapsible within this section.
- Category and doctor rows use compact summaries. Expanding them retains
  patient/visit counts, service groups, quantities, value, and salary details.
  Explanations and the mixed-status count caveat are available on info buttons.
- Unique patients and visits use `COUNT(DISTINCT ...)`. Manipulation item count
  and total quantity are separate measures.
- Original service value is the SQL sum of item `unit_price × quantity`,
  converted to the visit currency with the saved item exchange rate where
  necessary, matching the existing Services statistics valuation.
- For exactly 100% discount work, free service value equals original item
  service value. Neither amount is classified as income.
- Salary-bearing and non-salary-bearing visit/item counts, original value,
  and actual confirmed salary are reported separately. A mixed visit can
  occur in both status visit counts; those visit counts are not additive.
- Category, statistics group, doctor, and reason rows include distinct
  patient/visit counts, work quantity, original value, and confirmed salary.
  Category structure uses Therapy / Surgery / Orthopedics / Other and the
  existing `statistics_group` assignments. Unassigned catalog services remain
  direct rows; manual services remain under Other. Expanding Implantation
  shows its existing named services/brands.

## Salary attribution

The user explicitly selected **confirmed salary records only**. No salary
estimate or second salary formula is introduced.

The report joins `salary_settlement_items` to confirmed `salary_settlements`,
using `doctor_share_snapshot` (falling back to `doctor_share` for legacy null
snapshots). Direct visit salary is linked by `visit_treatment_case_id`.
Israeli lab salary is linked only through an actual visit item's unique
`lab_main_work_id`; unlinked lab quantities and their salaries do not enter
this report. The stored salary basis currencies GEL and USD are displayed
separately, including when payout currency differed.

Statuses distinguish positive confirmed salary, finalized declined salary, confirmed zero salary,
unfinalized/no confirmed record, salary-excluded services, and item-less
visits. Exclusions reuse `VisitTreatmentCase::salaryEligible()` rather than
defining another eligibility rule. Undoing a settlement removes it from
confirmed totals through the existing settlement data.

## Loading and performance

The initial page executes six aggregate report queries plus one doctor-option
query. It does not hydrate visits/patients or load patient names/comments.
All six report queries use SQL aggregates; PHP formats only grouped results.
There are no queries per doctor or service group, and no persistent cache.

Doctor/group expansion adds one aggregate service query. Clicking a summary,
reason, status, or Details action loads a scoped item list with simple
pagination: 25 rows per page, one detail query, no full-list count query.
Changing page filters closes details and clears the drill-down scope.

Measured with in-memory SQLite fixtures: six report queries for both 1 and
41 visits (approximately 3.6–5.0 ms database time), seven initial component queries
(approximately 4.1 ms), eight queries for the full HTTP page request including
page chrome (approximately 6.5 ms), and one on-demand detail query. These are local test
measurements, not a production-volume latency claim. The focused performance
test writes its latest measurements to
`storage/logs/full-discount-statistics-performance.json`.

## Deliberate boundaries

- Unfinalized salary is not estimated or counted as a finalized zero.
- A declined 100%-discount salary decision is finalized with zero actual salary;
  its potential amount is retained in the settlement item and is not added to
  salary cost totals. See [salary approval](full-discount-salary-approval.md).
- Cancelled visits and lab-only quantities are excluded.
- Visits without manipulation items still count as patients/visits, with
  zero item value and a visible explanatory note; values are not invented
  from consultation fees or lab rates.
- Current item values, visit dates, doctors, sources, and reasons are used
  alongside historical salary snapshots. This is not a reconstructed
  historical snapshot of the service catalog or discount reason.
- Existing reason strings and comments are displayed unchanged. No reason
  migration or new category assignment is performed.
- Currency ledgers remain separate; there is no conversion at current rates.

## Verification

`tests/Feature/FullDiscountStatisticsTest.php` covers exact discount selection,
quantities, zero patient payment, salary snapshots, CT/consultation exclusions,
mixed items, unique counts, filters, grouping, legacy reasons, item-less visits,
currency handling, linked Israeli lab work, unchanged Finance income, lazy
pagination, fixed query counts, localization, and access restrictions.
