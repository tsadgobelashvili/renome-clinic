<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Pages\Concerns\InteractsWithTechnicianSalary;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Models\Employee;
use App\Models\EmployeeSalarySettlement;
use App\Services\EmployeeSalaryService;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class TechnicianSalaries extends Page
{
    use AuthorizesPageAccess;
    use InteractsWithTechnicianSalary;
    use WithPagination;

    protected string $view = 'filament.pages.technician-salaries';

    protected static ?int $navigationSort = 22;

    #[Locked]
    public ?Employee $record = null;

    public bool $ready = false;

    public static function getNavigationLabel(): string
    {
        return __('employees.salary.overview');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationParentItem(): ?string
    {
        return LabCaseResource::getNavigationLabel();
    }

    public function openSalary(int $employee): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = Employee::activeTechnicians()->findOrFail($employee);
        $this->mountAction('calculateSalary');
    }

    public function openHistory(int $employee): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = Employee::activeTechnicians()->findOrFail($employee);
        $this->mountAction('salaryHistory');
    }

    public function overview(): array
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->ready) {
            return [];
        }
        $month = now()->format('Y-m');
        $records = Employee::activeTechnicians()->select('employees.*')
            ->addSelect([
                'last_finalized' => EmployeeSalarySettlement::select('settled_at')->whereColumn('employee_id', 'employees.id')
                    ->where('status', 'confirmed')->latest('settled_at')->latest('id')->limit(1),
                'opening_carry' => EmployeeSalarySettlement::select('closing_carry_gel')->whereColumn('employee_id', 'employees.id')
                    ->where('salary_type', 'performance')->where('status', 'confirmed')->latest('settled_at')->latest('id')->limit(1),
            ])->withExists(['salarySettlements as month_settled' => fn ($query) => $query->where('active_month', $month)])
            ->orderBy('first_name')->orderBy('id')->paginate(25);
        $totals = app(EmployeeSalaryService::class)->overviewTotals($records->getCollection(), $this->salaryPeriodFrom(), $this->salaryPeriodUntil());

        return compact('records', 'totals', 'month');
    }
}
