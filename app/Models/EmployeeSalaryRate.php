<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveTechnicianRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryRate extends Model
{
    use HasEffectiveTechnicianRate;

    protected $fillable = ['employee_id', 'work_type', 'amount', 'basis', 'is_active', 'effective_from'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean', 'effective_from' => 'date'];
    }

    protected function rateIdentityColumns(): array
    {
        return ['employee_id', 'work_type'];
    }

    public function deletionProtected(): bool
    {
        $from = $this->effective_from->toDateString();
        $until = $this->nextEffectiveDate();
        // Old settlements have rate snapshots rather than a rate FK. Preserve
        // every version whose effective interval can explain that snapshot,
        // including undone history and inactive stop versions.
        if (EmployeeSalarySettlementItem::query()->where('work_type', $this->work_type)
            ->whereHas('settlement', fn ($query) => $query->where('employee_id', $this->employee_id))
            ->where('work_date', '>=', $from)->when($until, fn ($query) => $query->where('work_date', '<', $until))->exists()) {
            return true;
        }
        $employee = $this->employee;
        $mainType = match ($this->work_type) {
            'zircon', 'zircon_modeling' => 'zircon', 'pmma', 'pmma_modeling' => 'pmma', 'main_other' => 'other', default => null,
        };
        $query = $mainType === null ? LabAdditionalWork::query()->where('work_type', $this->work_type === 'abutment_modeling' ? 'individual_abutment' : $this->work_type)
            : LabMainWork::query()->where('material', $mainType);

        return $query->whereHas('labCase', fn ($case) => $case->where('case_date', '>=', $from)
            ->when($until, fn ($case) => $case->where('case_date', '<', $until)))
            ->where(function ($work) use ($employee, $mainType): void {
                $work->where('technician_id', $this->employee_id);
                if ($mainType !== null && $employee?->user_id) {
                    $work->orWhereHas('labCase', fn ($case) => $case->where('modeled_by', $employee->user_id));
                }
                if ($employee?->salary_main_technician && ($mainType !== null || $this->work_type === 'individual_abutment')) {
                    $work->orWhereNotNull('lab_case_id');
                }
            })->exists();
    }

    public static function workTypes(): array
    {
        return [
            'zircon' => __('employees.salary.zircon_main'), 'pmma' => __('employees.salary.pmma_main'), 'main_other' => __('employees.salary.main_other'),
            'zircon_modeling' => __('employees.salary.zircon_modeling'), 'pmma_modeling' => __('employees.salary.pmma_modeling'),
            'abutment_modeling' => __('employees.salary.abutment_modeling'),
            'milling' => __('lab.additional_types.milling'),
            'splint' => __('lab.additional_types.splint'),
            'individual_abutment' => __('lab.additional_types.individual_abutment'),
            'titanium_bar_modeling' => __('lab.additional_types.titanium_bar_modeling'),
            'other' => __('lab.additional_types.other'),
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
