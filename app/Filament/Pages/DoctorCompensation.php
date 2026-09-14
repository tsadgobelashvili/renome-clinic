<?php

namespace App\Filament\Pages;

use App\Filament\Actions\DoctorSalaryAction;
use App\Filament\Concerns\InteractsWithDoctorSalary;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePayrollSetting;
use App\Models\SalarySettlement;
use App\Services\ClinicPayrollCycleService;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeePayrollService;
use App\Services\IsraeliLabSalaryItems;
use App\Support\Currency;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use UnitEnum;

class DoctorCompensation extends Page
{
    use InteractsWithDoctorSalary;

    protected string $view = 'filament.pages.doctor-compensation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?int $navigationSort = 45;

    public string $staffTypeFilter = 'doctors';

    #[Locked]
    public ?array $employeeDetail = null;

    #[Locked]
    public ?array $clinicPayrollReview = null;

    public function clinicPayrollAction(): Action
    {
        return Action::make('clinicPayroll')
            ->label(__('clinic-payroll.review'))
            ->modalHeading(__('clinic-payroll.review'))
            ->modalWidth('5xl')
            ->mountUsing(function (array $arguments) {
                abort_unless(static::canAccess(), 403);
                $this->clinicPayrollReview = isset($arguments['cycle'])
                    ? ClinicPayrollCycle::query()->where('status', 'finalized')->findOrFail($arguments['cycle'])->snapshot
                    : app(ClinicPayrollCycleService::class)->preview();
            })
            ->modalContent(fn () => view('filament.pages.partials.clinic-payroll-review', ['review' => $this->clinicPayrollReview]))
            ->modalSubmitAction(fn (Action $action) => $action->label(__('clinic-payroll.finalize'))
                ->visible(fn (array $arguments) => ! isset($arguments['cycle']) && auth()->user()?->isOwner()
                    && (count($this->clinicPayrollReview['doctors'] ?? []) + count($this->clinicPayrollReview['employees'] ?? [])) > 0))
            ->action(function (array $arguments) {
                abort_if(isset($arguments['cycle']) || ! $this->clinicPayrollReview, 422);
                app(ClinicPayrollCycleService::class)->finalize($this->clinicPayrollReview['payroll_date'], $this->clinicPayrollReview['fingerprint'], auth()->user());
                $this->clinicPayrollReview = null;
                Notification::make()->title(__('clinic-payroll.finalized'))->success()->send();
            });
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdministrator();
    }

    public static function getNavigationLabel(): string
    {
        return __('salaries.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getBreadcrumb(): string
    {
        return static::getNavigationLabel();
    }

    public function openDoctorSalary(int $doctorId, string $source = 'clinic'): void
    {
        abort_unless(in_array($source, ['clinic', 'israeli'], true), 422);
        Doctor::query()->where('is_active', true)->findOrFail($doctorId);
        $this->prepareDoctorSalary($doctorId);
        if ($source === 'israeli') {
            $pending = SalarySettlement::query()->unpaidAllocations()->where('doctor_id', $doctorId)->orderBy('id')->first();
            if ($pending && app(IsraeliLabSalaryItems::class)->eligible(Doctor::findOrFail($doctorId))->isEmpty()) {
                $this->mountAction('payIsraeliSalary', ['settlement' => $pending->id]);

                return;
            }
        }
        // Let the shared action choose its first unsettled record, including same-day work.
        $this->mountAction('calculateSalary', ['doctor' => $doctorId, 'source' => $source]);
    }

    private function employeesQuery()
    {
        return Employee::query()->where('is_active', true)
            ->whereHas('position', fn ($query) => $query->where('is_technician', false))
            ->with(['position', 'payrollSettings' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('first_name')->orderBy('last_name');
    }

    public function openEmployeeSalary(int $employeeId, string $source, int $entryId = 0): void
    {
        $employee = $this->employeesQuery()->findOrFail($employeeId);
        $row = collect(app(EmployeePayrollService::class)->payableSummaries(collect([$employee])))
            ->first(fn ($row) => $row['setting']->source === $source && ($row['entry']?->id ?? 0) === $entryId);
        abort_unless($row, 404);
        $entry = $row['entry'];
        $calculation = $entry ? $entry->toArray() : $row;
        $this->employeeDetail = [
            'employee_id' => $employee->id, 'entry_id' => $entryId,
            'name' => $employee->full_name, 'role' => $employee->position?->name ?? '—',
            'source' => $source, 'salary_model' => $calculation['salary_model'],
            'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
            'configured_rule' => $this->configuredRule($row['setting']),
            'base_amount' => (float) $calculation['base_amount'], 'gross_amount' => (float) $calculation['gross_amount'],
            'net_amount' => (float) $calculation['net_amount'], 'deductions' => (float) $calculation['deductions'],
            'required_amount' => $calculation['required_amount'] ?? null,
            'tax_breakdown' => array_intersect_key($calculation, array_flip(['income_tax', 'employee_pension', 'employer_pension'])),
            'currency' => $row['currency'], 'payment_method' => $row['payment_method'],
            'expected_date' => $employee->salary_payout_day ? $row['payday'] : null, 'status' => 'payable', 'can_finalize' => $entry === null,
        ];
        $this->dispatch('open-modal', id: 'employee-payroll-detail');
    }

    public function finalizeEmployeePayroll(): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);
        if (! $this->employeeDetail || ! $this->employeeDetail['can_finalize']) {
            return;
        }
        app(EmployeePayrollService::class)->finalize(
            $this->employeesQuery()->findOrFail($this->employeeDetail['employee_id']),
            $this->employeeDetail['source'], $this->employeeDetail['period_start'], $this->employeeDetail['period_end'],
        );
        $this->employeeDetail = null;
        $this->dispatch('close-modal', id: 'employee-payroll-detail');
        Notification::make()->title(__('employees.payroll.saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [DoctorSalaryAction::make(standalone: true)];
    }

    protected function getViewData(): array
    {
        $rows = $this->staffTypeFilter === 'employees' ? $this->employeeRows() : $this->doctorRows();

        return ['staffRows' => $rows, 'clinicPayroll' => app(ClinicPayrollCycleService::class)->overview(),
            'lastClinicPayroll' => ClinicPayrollCycle::query()->where('status', 'finalized')->orderByDesc('payroll_date')->first(['id', 'payroll_date'])];
    }

    private function doctorRows(): Collection
    {
        $doctors = Doctor::query()->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->get();
        $totals = app(DoctorCompensationCalculator::class)->payableSummaries($doctors);

        return $doctors->map(function (Doctor $doctor) use ($totals): array {
            $sources = $totals[$doctor->id] ?? [];

            return [
                'key' => 'doctor-'.$doctor->id, 'type' => 'doctor', 'id' => $doctor->id,
                'name' => $doctor->full_name, 'role' => $doctor->specialty ?: __('salaries.doctor'),
                'sources' => $sources, 'source' => collect($sources['clinic'] ?? [])->sum() > 0 ? 'clinic' : 'israeli',
                'payday' => null, 'payment_method' => null,
            ];
        })->filter(fn ($row) => collect($row['sources'])->flatten()->contains(fn ($amount) => $amount > 0))->values();
    }

    private function employeeRows(): Collection
    {
        $employees = $this->employeesQuery()->get();
        $payroll = app(EmployeePayrollService::class);
        foreach ($employees as $employee) {
            $employee->setRelation('payrollSettings', $payroll->effectiveSettings($employee->payrollSettings, CarbonImmutable::today()));
        }
        // The roster is authoritative. Optional salary summaries must not determine membership.
        $payrollEmployees = $employees->map(fn (Employee $employee) => (clone $employee)->setRelation(
            'payrollSettings', $employee->payrollSettings->filter(fn ($setting) => $this->salaryConfigured($setting)),
        ));
        $summaries = collect($payroll->payableSummaries($payrollEmployees))
            ->groupBy(fn ($row) => $row['employee']->id.'|'.$row['setting']->source);
        $lastClinicEntries = $payroll->latestFinalizedEntries($employees)->where('source', 'clinic')
            ->whereNotNull('clinic_payroll_cycle_id')->keyBy('employee_id');

        return $employees->flatMap(function (Employee $employee) use ($summaries, $payroll, $lastClinicEntries): Collection {
            $settings = $employee->payrollSettings->isEmpty() ? collect([null]) : $employee->payrollSettings;

            return $settings->flatMap(function (?EmployeePayrollSetting $setting) use ($employee, $summaries, $payroll, $lastClinicEntries): Collection {
                $rows = $summaries->get($employee->id.'|'.$setting?->source, collect());
                if ($rows->isEmpty()) {
                    $configured = $setting && $this->salaryConfigured($setting);
                    $lastCycle = $setting?->source === 'clinic' ? $lastClinicEntries->get($employee->id) : null;
                    $payoutMonth = $lastCycle
                        ? CarbonImmutable::parse($lastCycle->calculation_details['payout_date'] ?? $lastCycle->period_end)->startOfMonth()->addMonth()
                        : CarbonImmutable::today();

                    return collect([[
                        'key' => 'employee-'.$employee->id.'-'.($setting?->source ?? 'unconfigured'),
                        'type' => 'employee', 'id' => $employee->id, 'entry_id' => 0,
                        'name' => $employee->full_name, 'role' => $employee->position?->name,
                        'source' => $setting?->source, 'amounts' => $configured ? [$setting->currency => 0.0] : [],
                        'required_amounts' => [],
                        'salary_configured' => $configured, 'can_open' => false,
                        'payday' => $employee->salary_payout_day ? $payroll->payoutDate($employee, $payoutMonth)->toDateString() : null,
                        'payment_method' => $setting?->default_payment_method,
                    ]]);
                }

                return $rows->map(fn ($row) => [
                    'key' => 'employee-'.$row['employee']->id.'-'.$row['setting']->source.'-'.($row['entry']?->id ?? 'current'),
                    'type' => 'employee', 'id' => $row['employee']->id, 'entry_id' => $row['entry']?->id ?? 0,
                    'name' => $row['employee']->full_name, 'role' => $row['employee']->position?->name,
                    'source' => $row['setting']->source, 'amounts' => [$row['currency'] => $row['net_amount']],
                    'required_amounts' => $row['source'] === 'clinic' ? [$row['currency'] => $row['required_amount'] ?? $row['net_amount']] : [],
                    'salary_configured' => true, 'can_open' => true,
                    'payday' => $row['employee']->salary_payout_day ? $row['payday'] : null, 'payment_method' => $row['payment_method'],
                ]);
            });
        })->values();
    }

    private function salaryConfigured(EmployeePayrollSetting $setting): bool
    {
        $field = match ($setting->salary_model) {
            'fixed_net' => 'net_amount',
            'fixed_gross' => 'gross_amount',
            'percentage' => 'percentage_rate',
            'per_unit' => 'per_unit_amount',
            default => null,
        };

        return $field !== null && $setting->{$field} !== null;
    }

    private function configuredRule(EmployeePayrollSetting $setting): string
    {
        return match ($setting->salary_model) {
            'fixed_net' => Currency::format($setting->net_amount, $setting->currency),
            'fixed_gross' => Currency::format($setting->gross_amount, $setting->currency),
            'percentage' => number_format((float) $setting->percentage_rate, 2).'%',
            'per_unit' => Currency::format($setting->per_unit_amount, $setting->currency).' / '.__('employees.payroll.unit'),
        };
    }
}
