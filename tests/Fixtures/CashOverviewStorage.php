<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseSafety;

/** Only the columns read by Finance; ephemeral query fixtures, no migrations or seeders. */
final class CashOverviewStorage
{
    public static function create(): void
    {
        DatabaseSafety::assertInMemory(DB::connection()->getConfig());
        $schemas = [
            'finance_opening_balances' => 'source currency effective_date amount',
            'cashbox_days' => 'date status opening_balance opening_balance_usd carry_forward_balance carry_forward_balance_usd actual_closing_balance actual_closing_balance_usd',
            'cashbox_transactions' => 'cashbox_day_id type transaction_date amount currency payment_method description payment_id payment_split_id finance_transaction_id product_sale_id cash_transfer_id employee_advance_id employee_advance_key patient_id visit_id expense_category',
            'finance_transactions' => 'type transaction_date category amount currency payment_method cash_source description note clinic_cash_gel israeli_cash_gel funding_source payroll_entry_id reversal_of_finance_transaction_id expense_category_id expense_subcategory_id expense_direction_id expense_type_id purchase_id',
            'partner_finance_transactions' => 'source type transacted_at category from_account to_account amount currency from_amount to_amount from_currency to_currency exchange_rate notes employee_advance_key finance_transaction_id recipient expense_category_id expense_subcategory_id expense_direction_id expense_type_id',
            'partner_patient_payments' => 'patient_id amount currency payment_method paid_at notes deleted_at',
            'payments' => 'visit_id payment_date created_at deleted_at comment is_historical',
            'payment_splits' => 'payment_id payment_method amount currency',
            'patients' => 'first_name last_name',
            'visits' => 'patient_id',
            'product_sales' => 'patient_id total currency payment_method sold_at note',
            'expense_categories' => 'name reporting_code classification_dimension classification_code parent_id',
            'expense_subcategories' => 'name',
            'payroll_entries' => 'employee_id salary_advance_applied payment_method finalized_at source status net_amount currency',
            'employees' => 'first_name last_name',
            'employee_advance_entries' => 'employee_advance_id payroll_entry_id expense_direction_id expense_type_id kind expense_date amount description purchase_id',
            'employee_advances' => 'employee_id source currency bank_transaction_id',
            'purchase_items' => 'purchase_id purchase_product_id line_total',
            'purchase_products' => 'expense_direction_id',
            'bank_transactions' => 'bank_category_id expense_category_id expense_subcategory_id expense_type_id expense_direction_id exclude_from_pnl is_legacy direction amount currency transaction_date counterparty_name counterparty_account description operation_type gross_amount include_embedded_fee bank_fee bank account_identifier reference',
            'bank_categories' => 'accounting_treatment code expense_category_id',
            'bank_purchase_matches' => 'bank_transaction_id purchase_id amount',
            'users' => 'name email password role locale is_active created_at updated_at',
            'bog_sync_states' => 'account_number currency last_successful_sync_at',
        ];
        foreach ($schemas as $table => $columns) {
            $fields = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
            foreach (explode(' ', $columns) as $col) {
                $numeric = str_ends_with($col, '_id') || preg_match('/amount|total|balance|cash_gel|^is_|exclude_from_pnl|salary_advance_applied|include_embedded_fee|bank_fee|exchange_rate/', $col);
                $fields[] = '"'.$col.'" '.($numeric ? 'NUMERIC' : 'TEXT');
            }
            DB::statement('CREATE TABLE "'.$table.'" ('.implode(',', $fields).')');
        }

    }
}
