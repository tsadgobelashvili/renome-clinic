<?php

use App\Services\EmployeeSalaryService;
use App\Services\FinanceManager;

function seedTechnicianClinicCash(): void
{
    app(FinanceManager::class)->create([
        'type' => 'income', 'category' => 'other_income', 'transaction_date' => today()->subDay(),
        'amount' => 100000, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier',
    ]);
    \App\Models\CashboxDay::whereDate('date', today()->subDay())->update(['status' => 'closed', 'actual_closing_balance' => 100000, 'carry_forward_balance' => 0]);
}

function settleTechnicianWithClinicCash($employee, array $selected)
{
    $service = app(EmployeeSalaryService::class);
    $amount = $service->pending($employee)->only($selected)->sum('amount_gel');

    return $service->settle($employee, $selected, allocation: [
        'actual_paid_gel' => $amount, 'clinic_cash_gel' => $amount, 'israeli_cash_gel' => 0,
    ]);
}
