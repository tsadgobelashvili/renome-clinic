<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;

class BankAccounting
{
    public static function expenseSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        // Explicit non-expense treatments always win, even if an old classification remains.
        return "({$transaction}.direction = 'outflow' AND ({$category}.accounting_treatment = 'expense'
            OR (({$category}.id IS NULL OR {$category}.code = 'uncategorized') AND (
                EXISTS (SELECT 1 FROM expense_categories AS assigned_type WHERE assigned_type.id = {$transaction}.expense_type_id AND assigned_type.classification_dimension = 'type')
                OR EXISTS (SELECT 1 FROM expense_categories AS assigned_legacy WHERE assigned_legacy.id = {$transaction}.expense_category_id)
            ))))";
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

    /** Standalone fees are authoritative; unverified annotations still require opt-in. */
    public static function feeSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        return 'CASE WHEN '.self::expenseSql($transaction, $category)." AND ({$transaction}.operation_type = 'COM' OR {$category}.code = 'bank_fee'
            OR EXISTS (SELECT 1 FROM expense_categories AS fee_category WHERE (fee_category.id = {$transaction}.expense_type_id AND fee_category.classification_code = 'bank_fee')
                OR (fee_category.id = {$transaction}.expense_category_id AND fee_category.reporting_code = 'bank_fee')))
            THEN {$transaction}.amount ELSE ".self::embeddedFeeSql($transaction, $category).' END';
    }

    public static function embeddedFeeSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        $amount = self::withheldAmountSql($transaction);
        $match = self::uniqueFeePairSql($transaction, 'standalone_fee');

        // Only a proven one-to-one pair can replace this acquiring cost with a standalone debit.
        return "CASE WHEN {$transaction}.direction = 'inflow' AND {$category}.accounting_treatment = 'settlement'
            AND ({$amount}) > 0
            AND NOT EXISTS (SELECT 1 FROM bank_transactions AS standalone_fee
                WHERE {$match})
            THEN ({$amount}) ELSE 0 END";
    }

    private static function withheldAmountSql(string $transaction): string
    {
        return "CASE WHEN {$transaction}.gross_amount > {$transaction}.amount
            THEN ROUND({$transaction}.gross_amount - {$transaction}.amount, 2)
            WHEN {$transaction}.include_embedded_fee = TRUE THEN {$transaction}.bank_fee ELSE 0 END";
    }

    /** A matching standalone commission keeps its acquiring identity, but replaces the embedded cost. */
    public static function cardFeeSql(string $transaction = 'bank_transactions', string $category = 'bc'): string
    {
        $fee = self::feeSql($transaction, $category);
        $match = self::uniqueFeePairSql('settlement', $transaction);

        return "CASE WHEN {$transaction}.direction = 'inflow' THEN ".self::embeddedFeeSql($transaction, $category)."
            WHEN {$transaction}.direction = 'outflow' AND {$transaction}.operation_type = 'COM' AND EXISTS (
                SELECT 1 FROM bank_transactions AS settlement
                WHERE {$match}
            ) THEN ({$fee}) ELSE 0 END";
    }

    /** Ambiguous references are not paired: neither side may have another candidate. */
    private static function uniqueFeePairSql(string $settlement, string $fee): string
    {
        $pair = self::feePairCandidateSql($settlement, $fee);
        $otherSettlement = self::feePairCandidateSql('fee_peer_settlement', $fee);
        $otherFee = self::feePairCandidateSql($settlement, 'fee_peer_debit');

        return "({$pair}
            AND EXISTS (SELECT 1 FROM bank_categories AS pair_settlement_category
                WHERE pair_settlement_category.id = {$settlement}.bank_category_id AND pair_settlement_category.accounting_treatment = 'settlement')
            AND EXISTS (SELECT 1 FROM bank_categories AS pair_fee_category
                WHERE pair_fee_category.id = {$fee}.bank_category_id AND pair_fee_category.accounting_treatment = 'expense')
            AND NOT EXISTS (SELECT 1 FROM bank_transactions AS fee_peer_settlement
                WHERE fee_peer_settlement.id <> {$settlement}.id AND {$otherSettlement})
            AND NOT EXISTS (SELECT 1 FROM bank_transactions AS fee_peer_debit
                WHERE fee_peer_debit.id <> {$fee}.id AND {$otherFee}))";
    }

    private static function feePairCandidateSql(string $settlement, string $fee): string
    {
        $amount = self::withheldAmountSql($settlement);

        // entryId identifies each record, not its related commission. documentKey and free-text
        // descriptions are not proven fee links. Never infer a link from date/amount alone.
        return "({$settlement}.direction = 'inflow' AND {$fee}.direction = 'outflow' AND {$fee}.operation_type = 'COM'
            AND {$settlement}.bank = {$fee}.bank AND {$settlement}.currency = {$fee}.currency
            AND NULLIF(TRIM({$settlement}.account_identifier), '') IS NOT NULL
            AND {$settlement}.account_identifier = {$fee}.account_identifier
            AND NULLIF(TRIM({$settlement}.reference), '') IS NOT NULL
            AND LOWER(TRIM({$settlement}.reference)) NOT IN ('0', '-', '—', 'n/a', 'null')
            AND {$settlement}.reference = {$fee}.reference
            AND ({$amount}) > 0 AND ABS({$fee}.amount - ({$amount})) < 0.005)";
    }
}
