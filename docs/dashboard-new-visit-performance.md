# Dashboard New Visit mount

Measured with `DashboardVisitPerformanceTest` on local PHP 8.4 and isolated
SQLite, with 25 patients, visits, treatments and payments. The Dashboard is
already mounted before timing `mountAction('newVisit')`.

| Measurement | Before | First optimized run |
| --- | ---: | ---: |
| Mount request queries | 1 | 0 |
| SQL time | 0.09 ms | 0 ms |
| Livewire test mount/response time | 130.73 ms | 81.21 ms |
| Whole-test process peak memory | 90 MB | 90 MB |

These are individual local server/test measurements, not browser/network latency
or a claim that SQL caused the reported two-second delay. Subsequent runs vary.
The test writes its latest measurements to `storage/app/dashboard-visit-mount.json`.

The only mount query was the complete doctor ID/name list for the background
Visits toolbar. Its header view was constructed eagerly while configuring the
table, even though Filament only rendered the action modal. Deferring that view
until its header is actually rendered removes the unnecessary query/collection.

Patient and doctor fields already used async searches limited to 50 results.
They now select only label fields, retaining their existing search scopes and
patient-specific doctor ordering. Manipulation suggestions were already lazy:
blank input performs no query; typed input searches bounded catalog/history
results. No patient, treatment or global lookup cache was added.

The custom New Visit button previously waited for the Livewire round trip before
any modal was visible. It now opens a pre-rendered Filament loading shell locally
and makes the same single mount request. The real form still uses Filament's
modal lifecycle and partial rendering. The shell clears in `finally`, including
on request failure, guards double clicks, and does not restore focus over the
real form. No visit writes or calculations were moved into the opening path.

Verification: 49 focused Pest tests (504 assertions), JavaScript checks of
immediate dispatch/double-click prevention/success and failure cleanup, and
frontend build passed. Browser first-paint timing was not measured because no
connected browser session was available; the reported two-second delay was not
reproduced in the isolated server test.
