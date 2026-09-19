# Technician rate history and safe deletion

Implemented 19 September 2026. Technicians remain Employees selected by their technician position; no person records or separate payroll subsystem were added.

## Audit and configuration

The active `EmployeeSalaryService` already used stored employee rates and role flags, not personal names. Its limitation was one rate per employee/work type without effective dates. The obsolete `LabTechnicianRateSeeder` did contain named defaults and could overwrite legacy rates or create technician login accounts when reseeded. That seeder and its DatabaseSeeder call were removed.

Old seeder definitions were:

- Alex: production Zirconia 25, PMMA 5, abutment 10, milling 5 GEL/unit.
- Ilia: design Zirconia 10, PMMA 5, abutment 10, titanium bar modeling 30, milling 5.
- Mari Shavaeva: design Zirconia 5, PMMA 5, milling 5.
- Mari Ichukaidze: design Zirconia 3, milling 5.

These are audit facts, not new runtime defaults. Existing configured values take precedence; missing rates are never guessed. The local active profiles already contained their configured values. No name matching, new employees or new logins were needed for backfill.

## Existing tables extended

Migration: `2026_09_19_100000_version_technician_compensation_rates.php`.

`employee_salary_rates` now has effective dates and a unique employee/work-type/effective-date constraint. The existing legacy `lab_technician_rates` table receives the corresponding technician/work/component/date constraint so old user-linked Lab work also supports dated configuration. It remains a compatibility path; the operational Technician profile continues using Employee rates.

Existing rows receive the baseline date 1900-01-01 to preserve their previously undated applicability. Amounts, bases, active flags and payroll history are not rewritten. This is not an inferred employment start date; the employee's existing salary-effective-date gate remains in effect. Rollback refuses to discard multiple historical versions.

The migration was applied only to local PostgreSQL. All 12 originally inspected rate amounts were verified unchanged. Four additional records present at final verification were left untouched. Production still requires the normal deployment migration:

```sh
php artisan migrate --path=database/migrations/2026_09_19_100000_version_technician_compensation_rates.php
```

## Calculation and editing

- Latest version effective on the case/work date wins. A later inactive version stops payment rather than falling back to an earlier active one.
- Existing role flags, main-technician eligibility, performer/modeler attribution, PMMA/Zirconia same-case suppression and separate abutment components are unchanged.
- Work types reuse existing identifiers; rate basis remains per unit or per work.
- Rates already in effect are read-only: add a new dated version to change current pay or deactivate prospectively. Future versions can be edited. Duplicate dates for the same identity are rejected in the form/model and database.
- Finalized salary snapshots remain unchanged. Legacy work also refuses monetary/source edits after settlement.

## Delete behavior

The Technician profile edit repeater uses a compact table row with a trash icon at the far right, next to Active.

- Unsaved row: removed immediately without a modal.
- Persisted unused row: Georgian confirmation, then removed from the form; deletion is committed when the profile is saved. Canceling the form does not delete it.
- Used/historical row: blocked with a Georgian instruction to deactivate through a new inactive dated version instead.

The protection checks settlement snapshots for that employee/work type/effective interval, including undone audit history, and relevant Lab works that still need that version. It also protects inactive stop versions: deleting one must not revive old compensation. With no rate FK on old payroll snapshots, the check deliberately errs toward preserving history.

Protection is enforced in model deletion as well as the button. A forged repeater state cannot bypass it. The employee is locked during deletion to serialize with payroll finalization, and profile create/edit relationship saves are transactional. Deleting one rate does not delete other rates. No actual customer rate was deleted during verification.

## Files

- `app/Models/Concerns/HasEffectiveTechnicianRate.php`
- `app/Models/EmployeeSalaryRate.php`, `LabTechnicianRate.php`, `LabWorkItem.php`
- `app/Services/EmployeeSalaryService.php`
- `app/Filament/Resources/Employees/EmployeeResource.php`, `Pages/CreateEmployee.php`, `Pages/EditEmployee.php`
- `app/Filament/Resources/LabTechnicianRates/LabTechnicianRateResource.php`
- `resources/views/filament/resources/employees/view-employee.blade.php`
- `lang/en/employees.php`, `lang/ka/employees.php`
- Migration above; `database/seeders/DatabaseSeeder.php`; removed `LabTechnicianRateSeeder.php`
- `tests/Feature/TechnicianRateHistoryTest.php`, `EmployeeSalaryTest.php`, `InternalLabTest.php`

## Verification

88 focused tests passed, 658 assertions: TechnicianRateHistory, EmployeeSalary, TechnicianSalaryRoles, EmployeeSalaryFunding, InternalLab, EmployeePayroll and SharedLabCaseVisibility. Tests use isolated SQLite, not the application database.

Coverage includes per-technician/date rates, selected work types, renamed technicians, future/inactive versions, same-date rejection, historical snapshot preservation, value-preserving backfill, safe rollback refusal, immediate draft deletion, persisted confirmation, protected history, forged-state rejection and unaffected other rates.

Two stale InternalLab fixtures were updated to supply explicit doctor profile rates/percentage rather than expect name-based doctor defaults. No doctor calculation code changed. No RS, BOG, Finance calculation or funding logic changed. Future technician rate changes use the profile UI, not code.
