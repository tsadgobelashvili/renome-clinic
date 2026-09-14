<?php

return [
    'verified_fee' => 'Commission verified from gross and credited amounts; counted once, with any corresponding standalone commission taking precedence.',
    'pnl' => 'Profit & Loss', 'finance' => 'Finance', 'revenue' => 'Revenue', 'expenses' => 'Expenses', 'profit' => 'Profit',
    'money_source' => 'Money source', 'sources' => ['all' => 'All', 'cash' => 'Cash', 'bank' => 'Bank'],
    'source_help' => 'Cash means the existing RenoMe ledger, including patient card payments. Bank contributes classified expenses only. Revenue comes from ERP records. Currencies are reported separately.',
    'pnl_help' => 'Payment-based P&L: revenue less expenses. Settlements, deposits, transfers and amounts already recorded in ERP are excluded from Bank P&L. Salary reversals reduce expenses on their posting date.',
    'treatment' => 'P&L status',
    'treatments' => ['income' => 'Income', 'expense' => 'Expense', 'transfer' => 'Transfer · no P&L', 'settlement' => 'Settlement · no revenue', 'exclude' => 'Excluded', 'legacy' => 'Pre-cutover / Legacy'],
    'invalid_treatment' => 'Choose a valid accounting treatment.',
    'already_recorded' => 'Already recorded in ERP',
    'exclusion_help' => 'Excluded from Bank P&L only. Bank inflow, outflow and balances remain unchanged.',
    'fee_annotation' => 'Reported fee annotation', 'embedded_fee' => 'Include withheld fee as an expense',
    'embedded_fee_help' => 'Fees are included automatically when gross minus credited amount equals the reported fee. Otherwise enable only after confirming a withheld cost. A corresponding standalone commission takes precedence. Use Already recorded in ERP to exclude an existing expense.',
    'rules' => 'Categorization rules', 'add_rule' => 'Add rule', 'delete_rule' => 'Delete rule',
    'match_field' => 'Match field', 'phrase' => 'Contains phrase',
    'fields' => ['description' => 'Description', 'counterparty' => 'Counterparty / account', 'either' => 'Description or counterparty'],
    'rules_help' => 'Case-insensitive phrase matching. Safe BOG defaults run first, then the first active matching rule in creation order. New imports only; saved manual choices remain unchanged.',
    'apply_rules' => 'Apply to Uncategorized',
    'apply_help' => 'Apply active rules and safe BOG defaults to existing unclassified transactions. Manual categories, amounts and ERP exclusion flags will remain unchanged.',
    'applied' => ':count transactions categorized.',
    'periods' => ['14d' => '2 weeks', '1m' => '1 month', '3m' => '3 months', '6m' => '6 months', '1y' => '1 year', 'all' => 'All', 'custom' => 'Custom'],
];
