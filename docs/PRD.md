RenoMe Clinic ERP — Product Requirements Document

Version: 1.1
Status: Active / Living Document

1. Project Goal

RenoMe Clinic ERP is an internal clinic management system designed to replace fragmented Excel-based workflows with a fast, structured, and expandable ERP platform.

The system is initially intended for internal clinic use.

Core priorities:

very fast daily workflow

minimal unnecessary fields

clean and compact UI

avoid duplicate data entry

preserve historical data

support future ERP expansion

structure data from the beginning so that broad statistics and reporting are possible later

2. Technology Stack

Current stack:

Laravel

Filament v5

PostgreSQL

Windows local environment

Vite / npm

Codex as development assistant

Future architecture should allow:

multi-user access

role-based permissions

remote access

server/cloud migration if needed

multi-location support

3. UX Principles

Main rule:

Speed > unnecessary complexity

UI should remain minimal and task-focused.

Do not add fields only because they may be useful in the future.

Future capabilities may be prepared in the data model, but should not clutter the current UI.

Use conditional UI where appropriate.

Examples:

tomography-specific controls should only appear when tomography is selected

optional financial or reporting fields should not block daily workflow

forms should minimize clicks and vertical space

4. Patients

Patient fields:

first name

last name

phone

personal ID — optional

birth date

notes

Search:

first name

last name

phone

personal ID

Patient View should become the main patient history page.

A Patient may be linked to multiple assigned Doctors. Doctor specialty is read from the Doctor record and is not duplicated on the Patient assignment.

It should eventually contain:

visits

consultations

manipulations

payments

treatment plans

tomography

diagnoses

documents

exports

Future features:

PDF export

Word export

full patient history export

last activity

child/pediatric categorization

5. Doctors

Doctor fields:

first name

last name

phone

specialty

active/inactive

Doctor View should eventually include:

visit count

procedures performed

revenue

expenses

doctor production

period-based statistics

Doctor compensation can be calculated by Doctor and date period. The base is performed manipulation totals minus their direct expenses, multiplied by a configurable Doctor percentage. Detailed source Visit/manipulation rows must remain auditable. The calculation architecture should allow future category-specific, service-specific, and manipulation-exception percentage rules.

Doctor salary is calculated from Doctor View through a compact modal workflow. The modal shows period controls, totals, configurable Doctor percentage, a detailed Visit list for verification, and allows existing direct expenses to be reviewed or edited during calculation. A salary period may end at a specific Visit within the selected end date; this cutoff is calculated by Visit ID and stable Visit ordering, never by patient name. Salary settlements are historical snapshots: each settlement stores the exact Visit manipulation items included, their revenue, direct expenses, percentage, and Doctor share at confirmation time. Already settled work cannot be included again; work created later on the same calendar day remains available for the next settlement. Doctor View shows the latest settlement's last included Patient and Visit, and salary history exposes all included Visit/manipulation snapshot rows. Cashier posting of salary is future scope unless explicitly implemented later.

6. Visits

Visit represents treatment/work performed for a patient.

Visit Create/Edit should remain one of the fastest workflows in the system.

Main fields:

Patient

Doctor — optional

Date

Visit Type

Manipulations

Payment

Comment

Possible Visit Types:

Consultation

Treatment

Additional types may be added later.

6.1 Manipulations

A Visit may contain multiple manipulations.

Service Catalog is the primary source for Visit manipulations. A manual/custom manipulation may be entered only as a fallback when the required service does not exist in the catalog.

Manual entries are stored on the Visit line item and are not automatically added to Service Catalog. Reporting must distinguish catalog services from manual services.

Each manipulation may contain:

service

quantity

unit price

total

teeth / area

expense

manual price override

Default quantity:

1

The default quantity must exist in the real form state, not only visually.

Calculation:

quantity × unit price = total

6.2 Expenses

A manipulation may have related expenses.

Current compact UI should only require:

expense name

quantity

More advanced cost and inventory logic will be connected later through Inventory.

7. Service Catalog

Services are grouped into categories.

Initial categories:

Surgery

Orthopedics

Therapy

Periodontology

Orthodontics

Consultation

Tomography

A service may contain:

name

category

default price

active/inactive

optional price

Important rule:

A service should not be duplicated in the database just because it is used in multiple modules.

Manual Visit manipulation entries remain separate from catalog records and must not affect catalog-service statistics.

The same service may be used in:

Visits

Consultations

future modules

8. Tomography

Category:

Tomography

Services:

3D CT — 60 GEL

Panorama — 40 GEL

Quantity:

default = 1

Examples:

3D CT × 1 = 60 GEL

3D CT × 2 = 120 GEL

Panorama × 1 = 40 GEL

Panorama × 2 = 80 GEL

Tomography services may be used in both:

Consultation

Visit

The same Service records must be reused.

8.1 Tomography Source

Tomography should support patient source tracking:

Own patient

External clinic patient

UI labels:

ჩვენი პაციენტი

სხვა კლინიკიდან

Default:

ჩვენი პაციენტი

Do not add at this stage:

referring clinic

referring doctor

referral notes

Source exists for statistics and does not affect financial calculation.

9. Consultations

Consultation is a separate workflow.

Main fields:

Patient

Doctor — optional

Date

Source

Comment

Consultation should support:

consultation only

consultation + 3D CT

consultation + Panorama

only 3D CT

only Panorama

Tomography should not permanently occupy a large section of the form.

Use a compact action such as:

+ 3D CT

Clicking it should open a popup/modal with:

Service

Quantity

Source

Unit price

Total

Payment

Payment should ideally be handled in the same popup to avoid opening a second modal.

Doctor assignment is optional for every Visit and Consultation, regardless of patient source. Missing Doctor must not block saving, Payment, or Cashier creation.

10. Payments

Payment logic should be shared across the ERP.

Supported payment methods:

Cash

Card

Bank Transfer

Split payment

Example:

Total due: 200 GEL

Cash 50

Card 150

Paid total: 200

Remaining: 0

If the full amount is distributed, confirmation must succeed.

Avoid unsafe direct floating-point comparisons.

Preferred approaches:

integer tetri/minor units
or

controlled 2-decimal rounding

Payment logic should not be duplicated separately in Visits, Consultations, or other modules.

The ERP uses one centralized shared Payment architecture. Visits, Consultations, Tomography, and future payable modules must reuse the same Payment processor. Cash, card, and bank transfer—including split combinations—use the same minor-unit validation, database transaction, error handling, and Cashier posting flow. Payment business logic must not be duplicated per module.

On a new, unsaved Visit, Payment rows are validated and staged in the Create form state. They are persisted through the shared Payment processor only after the Visit exists. Visit, manipulations, Payment, and Cashier posting must commit atomically, without duplicate Visits or orphan financial records.

11. Cashier

Cashier is the central financial movement module.

Visit Payments and Consultation Payments must automatically appear in Cashier.

Main operation types:

Opening Balance

Patient Payment

Consultation Payment

Cash Expense

Card Expense

Cash Withdrawal

Other Expense

Day Closing

11.1 Daily Closing

Day-end logic should support:

opening balance

cash income

card income

cash expense

cash withdrawal

current cash balance

amount removed at closing

cash left for next day's change

Important:

Cash left for the next day must not be counted again as new revenue.

11.2 Cashier Table

Main columns should be simple and readable:

Date / Time

Type

Patient

Doctor

Cash

Card

Total

Action

Do not display mixed payment methods as one long text string.

Example:

Patient

Cash

Card

Total

John Smith

50

150

200

Cashier UX must remain easy to scan quickly.

12. Treatment Plans

Treatment Plan is patient-centric.

It should not permanently occupy space in Visit Create/Edit.

Patient View should contain:

treatment plans

stages

options

prices

PDF export

Word export

Stages:

I

II

III

If only one stage exists, the stage label may be hidden.

Options should follow the same compact principle.

13. Inventory / Stock

Inventory is a major future ERP module.

13.1 Main Warehouse

There should be a:

Main Warehouse

All received stock should first enter the main warehouse.

13.2 Stock Distribution

Main Warehouse stock should be transferable to:

treatment rooms

departments

laboratory

other warehouses/locations

Each transfer must record:

from

to

product

quantity

date

user

13.3 Stock Operations

Future inventory operations:

purchase / receipt

stock transfer

stock adjustment

write-off

damaged product

expired product

return

inventory count

13.4 Manipulation → Material Link

In a future stage, manipulations should be linkable to material consumption.

Example:

Composite Filling

may consume:

composite

bonding

etching

gloves

disposable materials

When a Visit is completed, related material stock may be automatically reduced.

This feature should not be mandatory in the initial phase.

14. Statistics & Reports

The ERP data architecture must be designed so that broad future statistics can be generated without major redesign.

Important dimensions:

Date

Day

Week

Month

Year

Patient

Doctor

Service

Service Category

Visit Type

Payment Method

Currency

Tomography Source

Inventory Product

Warehouse

Expense Type

14.1 Financial Statistics

Future reports should include:

total revenue

cash revenue

card revenue

expenses

net revenue

profit

average visit value

revenue per doctor

revenue per service

revenue per category

revenue per patient

period comparisons

14.2 Clinical Statistics

Future reports:

number of visits

number of consultations

number of patients

new patients

returning patients

services performed

procedures by doctor

procedures by category

14.3 Tomography Statistics

Future reports:

total 3D CT quantity

total Panorama quantity

tomography revenue

own patients

external clinic patients

revenue by source

CT during consultation

CT during treatment visit

14.4 Inventory Statistics

Future reports:

current stock

stock consumption

consumption by doctor

consumption by service

consumption by category

purchase prices

supplier comparison

monthly usage

stock value

low stock

expired items

waste

15. Import / Export

Import / Export is a core ERP requirement.

The system must support safe import and export of structured data.

15.1 Import

Import should support at minimum:

Patients

Doctors

Visits

Payments

Services / Manipulations

Future import support:

Inventory

Suppliers

Purchases

Expenses

Consultations

other structured data

A major goal is importing historical Excel data safely.

15.2 Import Formats

Minimum formats:

Excel

CSV

15.3 Import Preview

Before actual import, show preview information:

total rows found

valid rows

invalid rows

possible duplicates

rows that will be skipped

The user should be able to detect issues before import.

15.4 Column Mapping

Allow mapping Excel columns to ERP fields.

Examples:

სახელი → first_name

გვარი → last_name

ტელეფონი → phone

პირადი ნომერი → personal_id

This is important because historical Excel files may use different column names.

15.5 Duplicate Detection

Imports must avoid unnecessary duplicate records.

Patient duplicate detection may use combinations of:

personal ID

phone

first name + last name

other identifiers

Logic must be flexible because some fields may be missing.

15.6 Create vs Update

Future import modes:

Create new only

Update existing

Create + update

Updates should not overwrite existing data without proper validation.

15.7 Import Errors

After import, provide an error report.

Example fields:

row number

problem

value

reason

One invalid row should not necessarily fail the entire import.

15.8 Historical Data Import

Historical Excel migration should be performed in stages:

backup

test import

preview

duplicate check

validation

sample verification

final import

Large imports should not be run directly on live data without validation.

15.9 Export

Export should support at minimum:

Patients

Visits

Payments

Consultations

Cashier

Services

Tomography

Statistics / Reports

Future exports:

Inventory

Purchases

Suppliers

Expenses

Salary reports

15.10 Export Formats

Minimum:

Excel

CSV

Where relevant:

PDF

Word

15.11 Export Filters

Export should respect active UI filters.

Examples:

date range

doctor

patient

visit status

service

category

payment method

source

If the user filters one month and one doctor, export should contain only that filtered result set.

15.12 Import / Export Safety

Important operations should use:

validation

database transaction where appropriate

rollback capability where practical

logging

Import/export must not damage existing data.

16. Documents

Future document features:

Patient history PDF

Patient history Word

Treatment plan PDF

Treatment plan Word

Calculation

Form 100

Insurance documents

Georgian characters and the ₾ symbol must render correctly in PDF exports.

17. ICD / Diagnosis / Form 100

Future module.

Should include:

ICD-10 diagnosis catalog

code

Georgian name

optional description

Visit should eventually support diagnosis selection.

Form 100 export should include:

diagnosis

ICD code

treatment information

doctor

patient

18. Users & Roles

Future roles:

Owner

Administrator

Doctor

Cashier

Warehouse User

Permissions should be granular.

Examples:

Administrator may access Visits but not all financial reports.

Doctor may access their own visits and relevant patient records.

19. Audit Log

Important changes should eventually be auditable.

Examples:

payment edited

visit edited

price changed

stock adjusted

cash withdrawn

expense changed

imported data updated

Store:

who

what

old value

new value

when

20. Performance

The system must remain fast as data volume grows.

Important:

database indexes

optimized queries

pagination

eager loading where appropriate

avoid N+1 queries

efficient reporting queries

Performance is a high priority.

21. Backup & Data Safety

Future data safety requirements:

regular database backup

backup before large imports

restore procedure

migration safety

data integrity checks

Large imports, migrations, or financial schema changes should not be executed without backup.

22. NOW / NEXT / LATER

NOW

Current priorities:

Patients

Doctors

Visits

Manipulations

Consultations

Tomography

Payments

Cashier

UX polishing

bug fixing

NEXT

Next stage:

Cashier refinement

Reports / Statistics foundation

Import / Export

Treatment Plans

Patient exports

roles

audit history

LATER

Future ERP expansion:

Main Warehouse

Sub-warehouses

Inventory transfers

Manipulation → Materials

Automatic stock consumption

Salary calculations

Doctor profitability

Advanced reports

Form 100

ICD codes

full audit system

multi-location support

23. Development Rules for Codex

Before implementing any feature:

Read this PRD.

Inspect the existing implementation.

Reuse existing models, services, payment logic, and relationships where possible.

Do not create duplicate business logic.

Do not change unrelated modules.

Preserve existing working functionality.

Prefer small, isolated changes.

Do not implement NEXT or LATER scope unless explicitly requested.

Database migrations must preserve existing data.

UI must remain compact and fast.

New features must preserve future reporting capability.

Financial and inventory operations should be auditable.

Import operations must be safe and validated.

If a new explicit user instruction conflicts with this PRD, the latest explicit instruction overrides the PRD.

24. PRD Maintenance

This is a living document.

When product behavior changes:

update only the relevant PRD section

do not rewrite unrelated sections

preserve version history with Git

implementation should follow the latest PRD state

The PRD is not immutable.

Workflow, UI, prices, categories, reporting, payment behavior, and future scope may all change during development.
