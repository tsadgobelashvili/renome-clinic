<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Pages\Concerns\InteractsWithTechnicianSalary;
use App\Models\Employee;
use App\Models\EmployeeSalarySettlement;
use App\Services\EmployeeSalaryService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class TechnicianSalaries extends Page
{
    use AuthorizesPageAccess;
    use InteractsWithTechnicianSalary;
    use WithPagination;

    protected string $view = 'filament.pages.technician-salaries';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    #[Locked]
    public ?Employee $record = null;

    public bool $ready = false;

    public static function getNavigationLabel(): string
    {
        return app()->getLocale() === 'ka' ? 'ტექნიკოსების ხელფასები' : 'Technician salaries';
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'HR / პერსონალი';
    }

    public function openSalary(int $employee): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = Employee::activeTechnicians()->findOrFail($employee);
        $this->mountAction('calculateSalary');
    }

    public function openMonthlySalary(int $employee): void
    {
        abort_unless(static::canAccess() && auth()->user()?->isOwner(), 403);
        $this->record = Employee::activeTechnicians()->findOrFail($employee);
        $this->mountAction('monthlySalary');
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
        $query = Employee::activeTechnicians()->select('employees.*')
            ->addSelect([
                'last_finalized' => EmployeeSalarySettlement::select('settled_at')->whereColumn('employee_id', 'employees.id')
                    ->where('status', 'confirmed')->latest('settled_at')->latest('id')->limit(1),
                'opening_carry' => EmployeeSalarySettlement::select('closing_carry_gel')->whereColumn('employee_id', 'employees.id')
                    ->where('salary_type', 'performance')->where('status', 'confirmed')->latest('settled_at')->latest('id')->limit(1),
            ])->withExists(['salarySettlements as month_settled' => fn ($query) => $query->where('active_month', $month)])
            ->orderBy('first_name')->orderBy('id');
        $records = (clone $query)->paginate(25);
        $totals = app(EmployeeSalaryService::class)->overviewTotals($query->get(), $this->salaryPeriodFrom(), $this->salaryPeriodUntil());

        $monthlySchedules = $records->getCollection()->mapWithKeys(fn (Employee $employee) => [
            $employee->id => app(EmployeeSalaryService::class)->monthlySchedule($employee),
        ])->all();
        $grandTotal = round(array_sum($totals), 2);

        return compact('records', 'totals', 'month', 'grandTotal', 'monthlySchedules');
    }
}
