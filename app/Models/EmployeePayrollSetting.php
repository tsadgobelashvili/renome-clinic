<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class EmployeePayrollSetting extends Model
{
    public const SOURCES = ['clinic', 'israeli'];

    public const SALARY_MODELS = ['fixed_net', 'fixed_gross', 'percentage', 'per_unit'];

    protected $fillable = [
        'employee_id', 'source', 'salary_model', 'currency', 'default_payment_method',
        'effective_from', 'is_active', 'net_amount', 'gross_amount', 'percentage_rate',
        'per_unit_amount', 'category', 'treatment_case_id', 'taxable',
        'employee_deductions', 'employer_cost', 'tax_settings_reference',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'is_active' => 'boolean',
            'taxable' => 'boolean',
            'net_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'percentage_rate' => 'decimal:4',
            'per_unit_amount' => 'decimal:2',
            'employee_deductions' => 'decimal:2',
            'employer_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            if (! in_array($setting->source, self::SOURCES, true)) {
                throw ValidationException::withMessages(['source' => __('employees.payroll.invalid_source')]);
            }
            if (! in_array($setting->salary_model, self::SALARY_MODELS, true)) {
                throw ValidationException::withMessages(['salary_model' => __('employees.payroll.invalid_model')]);
            }
            if (! Currency::isSupported($setting->currency)) {
                throw ValidationException::withMessages(['currency' => __('employees.payroll.invalid_currency')]);
            }
            if (! in_array($setting->default_payment_method, [PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value], true)) {
                throw ValidationException::withMessages(['default_payment_method' => __('employees.payroll.invalid_payment_method')]);
            }

            $requiredAmount = match ($setting->salary_model) {
                'fixed_net' => 'net_amount',
                'fixed_gross' => 'gross_amount',
                'percentage' => 'percentage_rate',
                'per_unit' => 'per_unit_amount',
            };
            if ($setting->{$requiredAmount} === null || (float) $setting->{$requiredAmount} < 0) {
                throw ValidationException::withMessages([$requiredAmount => __('employees.payroll.required_amount')]);
            }
            if ($setting->salary_model === 'percentage' && (float) $setting->percentage_rate > 100) {
                throw ValidationException::withMessages(['percentage_rate' => __('employees.payroll.invalid_percentage')]);
            }
            foreach (['employee_deductions', 'employer_cost'] as $field) {
                if ((float) ($setting->{$field} ?? 0) < 0) {
                    throw ValidationException::withMessages([$field => __('employees.payroll.non_negative')]);
                }
            }

            if (! in_array($setting->salary_model, ['percentage', 'per_unit'], true)) {
                $setting->category = null;
                $setting->treatment_case_id = null;
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function treatmentCase(): BelongsTo
    {
        return $this->belongsTo(TreatmentCase::class);
    }
}
