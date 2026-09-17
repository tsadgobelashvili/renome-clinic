<?php

return [
    'configuration_missing' => 'BOG is not configured: :field is missing. Ask your administrator to configure the integration.',
    'last_sync' => 'Last sync',
    'live_balance' => 'BOG live balance',
    'balance_unavailable' => 'Balance unavailable',
    'balance_loading' => 'Refreshing live balance…',
    'title' => 'BOG Transactions', 'operation_date' => 'Operation date', 'counterparty_name' => 'Counterparty',
    'description' => 'Description / purpose', 'debit' => 'Debit / outgoing', 'credit' => 'Credit / incoming',
    'currency' => 'Currency', 'operation_type' => 'Operation type', 'status' => 'Status', 'direction' => 'Direction',
    'dates' => 'Date range', 'from' => 'From', 'until' => 'To', 'details' => 'Transaction details', 'change_status' => 'Change status',
    'statuses' => ['unreviewed' => 'Unreviewed', 'categorized' => 'Categorized', 'ignored' => 'Ignored', 'matched' => 'Matched'],
    'sync' => 'Sync BOG', 'local_only' => 'BOG API sync is currently local-only.',
    'sync_summary' => 'Fetched: :fetched · Inserted: :inserted · Duplicates: :duplicates · Errors: :errors',
    'database_error' => 'Database insert failed; no rows were committed. Check the BOG migration and database connection.',
    'id' => 'ID', 'entry_id' => 'Entry ID', 'account_number' => 'Account', 'value_date' => 'Value date',
    'counterparty_account' => 'Counterparty account', 'counterparty_bank' => 'Counterparty bank',
    'created_at' => 'Imported at', 'updated_at' => 'Updated at', 'raw_payload' => 'Raw API payload (read-only)',
];
