# Panel authorization

## Role contract

Only active `owner`, `administrator`, and `lab_technician` users may enter the panel.

- Owner: all existing modules.
- Administrator: Dashboard, Cashbox, patients (including Israeli patient operations/payments), visits, doctors, treatment catalog/plans, and existing operational compensation functions. No Bank, Finance/reporting, RS, personnel administration, or owner-only payroll history.
- Lab Technician: shared Lab cases, own profile, logout. No creator/technician ownership restriction on the queue. Destructive Lab case operations are Owner-only.

## Resources (16)

Laravel discovers policies from `App\Policies` by model name. Filament strict authorization is enabled: a missing policy or requested policy method is a configuration error, never implicit permission.

| Resources | Model policies / exception |
| --- | --- |
| Doctors | DoctorPolicy |
| Patients, PartnerPatients | PatientPolicy |
| Visits | VisitPolicy |
| TreatmentCases | TreatmentCasePolicy |
| TreatmentEstimates | TreatmentEstimatePolicy |
| DirectExpenses | VisitPolicy plus Owner-only resource entry; ordinary Visits remain operational |
| Employees, LabTechnicians | EmployeePolicy; the legacy technician resource still disables create/delete |
| EmployeePositions | EmployeePositionPolicy |
| LabCases | LabCasePolicy: Owner/Lab operational access, Owner destructive access |
| LabTechnicianRates | LabTechnicianRatePolicy |
| Purchases | PurchasePolicy; PurchaseItem/PurchaseProduct policies protect related classification records |
| ProductMaterials | ProductPolicy |
| PartnerFinance | PartnerFinanceEntryPolicy |
| Users | UserPolicy |

RecordPolicy defines standard CRUD/bulk/restore/replicate abilities. ClinicalOperationsPolicy and OwnerRecordsPolicy define audiences. Patient payment models use clinical policies so related payment visibility remains available to Administrators.

## Custom pages (15)

`PanelPageAccess` is the explicit class-to-capability registry. The `access-panel-page` Gate delegates to it. `AuthorizesPageAccess::canAccess()` uses this same Gate for navigation, mounting, hydration, and existing page action guards.

- Clinical: Dashboard, Cashbox, DoctorCompensation.
- Owner: Bank, BankCategories, BankRules, BogTransactions, ExpenseCategories, ExternalLabOrders, Finance, FinanceOpeningBalances, FinanceReports, FullDiscountStatistics, LabSalaries, ProfitLoss.
- Filament EditProfile is explicitly allowed for recognized active roles.

Unlisted classes and subclasses are denied. Persistent AuthorizePanelPage middleware checks the route's component **class**, not its URL, so forgetting the trait on a new page does not expose its HTTP route. Resource pages enforce their own policies. RestrictLabTechnicianAccess retains only activity checking and the Lab Dashboard landing redirect; it has no URL-prefix security rules.

`User::canViewSalaryHistory()` remains the shared Owner-only rule for finalized payroll review and doctor salary history. Current calculation/payout workflows retain their existing action restrictions.

## Adding a module

1. Resource: add the model policy, including every ability used by its actions. Use resource hooks only for a documented exception when multiple resources share a model.
2. Custom page: use AuthorizesPageAccess (or inherit it), and explicitly register the exact class in PanelPageAccess with the intended capability. Inheritance does not automatically grant access to a new page.
3. Keep custom mutations authorized server-side; navigation visibility is not authorization.
4. Test direct URLs, Livewire hydration/actions after role revocation, unknown/inactive users, and intended Owner/Administrator/Lab access. AuthorizationPhaseTwoTest enumerates every registered Resource/Page and tests synthetic unconfigured modules.

No global Administrator bypass exists. These rules do not alter financial calculations, imports, matching, or payroll formulas.
