# Statistics historical NBG rates

Create the rates table, then fill only dates with USD activity used by Statistics:

```sh
php artisan migrate --path=database/migrations/2026_09_22_180000_create_exchange_rates_table.php
php artisan exchange-rates:backfill
```

Run the backfill again after new USD activity/imports. It skips stored dates and never changes transaction or accounting records. No scheduler or other Finance workflow is changed.

`exchange_rates` stores one USD/GEL rate per activity date. Its unique `(currency, date)` constraint protects concurrent/repeated runs. `effective_date` records the official date used, including a preceding business day for a holiday/weekend.

The existing NBG service handles missing dates in bounded HTTP batches. Empty date responses search backward (up to 31 days); outages or invalid responses fail explicitly, never falling back to today's rate. Previously persisted rows remain intact and the command can be retried.

Statistics bulk-reads rates and makes no HTTP requests, even on a missing date. Missing rates show an unavailable state until backfill completes. GEL-only and USD-only views remain usable without exchange rates. All-currency KPIs, breakdowns and trends use the same stored daily rates.
