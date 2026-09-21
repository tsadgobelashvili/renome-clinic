<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Employee extends Model
{
    /** Bounded, name-only options shared by employee selectors. */
    public static function activeNameOptions(string $search = ''): array
    {
        $normalize = static fn (string $name): string => mb_strtolower(
            \App\Support\GeorgianNameTransliterator::transliterate(mb_strtolower(trim($name))) ?? trim($name)
        );
        $terms = preg_split('/\s+/u', $normalize($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $options = [];

        foreach (static::query()->where('is_active', true)->select(['id', 'first_name', 'last_name'])->lazyById(200) as $employee) {
            $name = $normalize($employee->full_name);
            if (collect($terms)->every(fn (string $term): bool => str_contains($name, $term))) {
                $options[$employee->id] = $employee->full_name;
                if (count($options) === 30) {
                    break;
                }
            }
        }

        return $options;
    }

    protected $fillable = ['show_in_lab_doctor_list', 'first_name', 'last_name', 'birth_date', 'personal_id', 'phone', 'position_id', 'is_active', 'user_id', 'salary_type', 'salary_active', 'salary_effective_from', 'monthly_salary_gel', 'salary_payment_schedule', 'salary_payout_day', 'salary_main_technician', 'salary_modeler', 'salary_milling_eligible', 'salary_abutment_eligible', 'salary_balk_eligible'];

    protected function casts(): array
    {
        return ['show_in_lab_doctor_list' => 'boolean', 'birth_date' => 'date', 'is_active' => 'boolean', 'salary_active' => 'boolean', 'salary_effective_from' => 'date', 'monthly_salary_gel' => 'decimal:2', 'salary_payout_day' => 'integer', ...array_fill_keys(array_keys(self::salaryRoles()), 'boolean')];
    }

    public static function salaryRoles(): array
    {
        return collect(['salary_main_technician', 'salary_modeler', 'salary_milling_eligible', 'salary_abutment_eligible', 'salary_balk_eligible'])
            ->mapWithKeys(fn (string $field): array => [$field => __('employees.salary.'.$field)])->all();
    }

    public function scopeLabDoctorAssistants(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_in_lab_doctor_list', true)
            ->whereHas('position', fn (Builder $position): Builder => $position->assistant());
    }

    public function assistantLabCases(): HasMany
    {
        return $this->hasMany(LabCase::class, 'assistant_employee_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->salary_payout_day !== null
                && ($employee->salary_payout_day < 1 || $employee->salary_payout_day > 31)) {
                throw ValidationException::withMessages([
                    'salary_payout_day' => __('employees.payroll.invalid_payout_day'),
                ]);
            }
        });

        static::deleting(function (Employee $employee): void {
            if ($employee->advances()->exists() || $employee->assistantLabCases()->exists() || $employee->additionalLabWorks()->exists() || $employee->mainLabWorks()->exists() || $employee->salarySettlements()->exists() || $employee->payrollEntries()->exists()) {
                throw ValidationException::withMessages([
                    'employee' => __('employees.delete_blocked'),
                ]);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(EmployeePosition::class);
    }

    public function scopeActiveTechnicians(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('position', fn (Builder $position): Builder => $position
                ->where('is_active', true)
                ->where('is_technician', true));
    }

    public function additionalLabWorks(): HasMany
    {
        return $this->hasMany(LabAdditionalWork::class, 'technician_id');
    }

    public function mainLabWorks(): HasMany
    {
        return $this->hasMany(LabMainWork::class, 'technician_id');
    }

    public function salaryRates(): HasMany
    {
        return $this->hasMany(EmployeeSalaryRate::class);
    }

    public function salarySettlements(): HasMany
    {
        return $this->hasMany(EmployeeSalarySettlement::class);
    }

    public function payrollSettings(): HasMany
    {
        return $this->hasMany(EmployeePayrollSetting::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }
}
