<?php

namespace App\Services;

/** Clinic employee funding requirement; never used for doctor or Israeli payroll. */
class ClinicEmployeePayrollAmounts
{
    public static function fromNet(mixed $value, ?string $paymentMethod = 'bank_transfer'): array
    {
        $net = is_numeric($value) ? round(max(0, (float) $value), 2) : 0.0;
        if ($paymentMethod === 'cash') {
            return ['net_amount' => $net, 'taxable_salary' => $net, 'gross_amount' => $net,
                'income_tax' => 0.0, 'employee_pension' => 0.0, 'employer_pension' => 0.0,
                'deductions' => 0.0, 'employer_cost' => 0.0, 'required_amount' => $net];
        }
        $taxable = $net / 0.80;
        $gross = $taxable / 0.98;
        $pension = $gross * 0.02;

        return [
            'net_amount' => $net,
            'taxable_salary' => round($taxable, 2),
            'gross_amount' => round($gross, 2),
            'income_tax' => round($taxable - $net, 2),
            'employee_pension' => round($pension, 2),
            'employer_pension' => round($pension, 2),
            'deductions' => round($taxable - $net + $pension, 2),
            'employer_cost' => round($pension, 2),
            'required_amount' => round($gross + $pension, 2),
        ];
    }
}
