<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;

class BankAccounting
{
    public static function expenseSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        return "({$transaction}.direction = 'outflow' AND ({$category}.accounting_treatment = 'expense' OR {$category}.id IS NULL OR {$category}.code = 'uncategorized'))";
    }

    public static function status(BankTransaction $transaction, string $treatment): string
    {
        if ($transaction->is_legacy) {
            return 'legacy';
        }
        if ($transaction->exclude_from_pnl) {
            return 'already_recorded';
        }
        if (($treatment === 'income' && $transaction->direction !== 'inflow') || ($treatment === 'expense' && $transaction->direction !== 'outflow')) {
            return 'exclude';
        }

        return $treatment;
    }

    /** Standalone fees are authoritative; annotations are opt-in only for withheld settlement fees. */
    public static function feeSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        return 'CASE WHEN '.self::expenseSql($transaction, $category)." AND ({$transaction}.operation_type = 'COM' OR {$category}.code = 'bank_fee'
            OR EXISTS (SELECT 1 FROM expense_categories AS fee_category WHERE fee_category.id = {$transaction}.expense_category_id AND fee_category.reporting_code = 'bank_fee'))
            THEN {$transaction}.amount ELSE ".self::embeddedFeeSql($transaction, $category).' END';
    }

    public static function embeddedFeeSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        // A proven gross - net difference is a withheld cost. A matching standalone COM wins,
        // including when it arrives in a later overlapping import. No ERP matching is performed.
        return "CASE WHEN {$transaction}.direction = 'inflow' AND {$category}.accounting_treatment = 'settlement'
            AND {$transaction}.bank_fee > 0
            AND ({$transaction}.include_embedded_fee = TRUE OR ABS({$transaction}.gross_amount - {$transaction}.amount - {$transaction}.bank_fee) < 0.005)
            AND NOT EXISTS (SELECT 1 FROM bank_transactions AS standalone_fee
                WHERE standalone_fee.bank = {$transaction}.bank
                AND COALESCE(standalone_fee.account_identifier, '') = COALESCE({$transaction}.account_identifier, '')
                AND standalone_fee.currency = {$transaction}.currency AND standalone_fee.direction = 'outflow' AND standalone_fee.operation_type = 'COM'
                AND ((NULLIF({$transaction}.reference, '') IS NOT NULL AND standalone_fee.reference = {$transaction}.reference)
                    OR ((NULLIF(standalone_fee.reference, '') IS NULL OR NULLIF({$transaction}.reference, '') IS NULL)
                        AND DATE(standalone_fee.transaction_date) = DATE({$transaction}.transaction_date) AND ABS(standalone_fee.amount - {$transaction}.bank_fee) < 0.005)))
            THEN {$transaction}.bank_fee ELSE 0 END";
    }
}
