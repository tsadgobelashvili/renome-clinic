<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Concerns\RedirectsToCanonicalPersonUrl;
use App\Filament\Pages\Concerns\InteractsWithTechnicianSalary;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\LabAdditionalWork;
use App\Models\LabCase;
use App\Models\LabMainWork;
use App\Services\EmployeePayrollService;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewEmployee extends ViewRecord
{
    use InteractsWithTechnicianSalary;
    use RedirectsToCanonicalPersonUrl;

    protected static string $resource = EmployeeResource::class;

    protected string $view = 'filament.resources.employees.view-employee';

    protected function getHeaderActions(): array
    {
        $actions = [EditAction::make()];
        if ($this->record->position?->is_technician) {
            return [...$actions, $this->calculateSalaryAction(), $this->salaryHistoryAction()];
        }

        return [...$actions, $this->calculatePayrollAction(), $this->payrollHistoryAction()];
    }

    public function calculatePayrollAction(): Action
    {
        return Action::make('calculatePayroll')->label(__('employees.payroll.calculate'))
            ->visible(fn (): bool => $this->record->is_active && $this->record->payrollSettings()->where('is_active', true)->exists())
            ->modalWidth('3xl')->modalSubmitActionLabel(__('employees.payroll.finalize'))
            ->modalCancelActionLabel(__('employees.salary.close'))
            ->schema([
                Select::make('source')->label(__('employees.payroll.source'))->options(fn (): array => $this->record
                    ->payrollSettings()->where('is_active', true)->pluck('source')->mapWithKeys(fn (string $source): array => [
                        $source => __('employees.payroll.'.$source),
                    ])->all())->native(false)->required()->live(),
                DatePicker::make('period_start')->label(__('employees.payroll.period_start'))->default(now()->startOfMonth())->native(false)->displayFormat('d.m.Y')->live(),
                DatePicker::make('period_end')->label(__('employees.payroll.period_end'))->default(now())->native(false)->displayFormat('d.m.Y')->afterOrEqual('period_start')->live(),
                Placeholder::make('preview')->hiddenLabel()->content(fn (Get $get): HtmlString => $this->payrollPreview($get))->columnSpanFull(),
            ])->action(function (array $data): void {
                abort_unless(auth()->user()?->isOwner(), 403);
                app(EmployeePayrollService::class)->finalize(
                    $this->record,
                    $data['source'],
                    $data['period_start'],
                    $data['period_end'],
                );
                $this->record->refresh();
                Notification::make()->title(__('employees.payroll.saved'))->success()->send();
            });
    }

    public function payrollHistoryAction(): Action
    {
        return Action::make('payrollHistory')->label(__('employees.payroll.history'))->color('gray')
            ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('employees.salary.close'))
            ->modalContent(fn () => view('filament.resources.employees.payroll-history', [
                'entries' => $this->record->payrollEntries()->latest('finalized_at')->get(),
            ]));
    }

    public function performedWorks(): Collection
    {
        $employee = $this->record;
        $rows = collect();
        foreach (['main' => LabMainWork::class, 'additional' => LabAdditionalWork::class] as $kind => $model) {
            $works = $model::query()->where(function ($query) use ($employee, $kind): void {
                $query->where('technician_id', $employee->id);
                if ($kind === 'main' && $employee->user_id) {
                    $query->orWhereHas('labCase', fn ($case) => $case->where('modeled_by', $employee->user_id));
                }
            })->with('labCase.patient')->get();
            foreach ($works as $work) {
                $case = $work->labCase;
                if ($kind === 'main') {
                    $modeling = $case->modeled_by !== null
                        ? $employee->user_id && (int) $case->modeled_by === (int) $employee->user_id
                        : $employee->salary_modeler;
                    $label = LabCase::MATERIALS[$work->material] ?? $work->material;
                    $role = __('employees.salary.'.($modeling ? 'salary_modeler' : 'salary_main_technician'));
                } else {
                    $label = __('lab.additional_types.'.$work->work_type);
                    $role = __('lab.technician');
                }
                $rows->push(['date' => $case->case_date, 'patient' => $case->patient?->full_name ?? '—',
                    'work' => $label, 'quantity' => $work->quantity, 'role' => $role, 'source' => $case->source]);
            }
        }

        return $rows->sortByDesc('date');
    }

    private function payrollPreview(Get $get): HtmlString
    {
        if (! $get('source') || ! $get('period_start') || ! $get('period_end') || $get('period_start') > $get('period_end')) {
            return new HtmlString('<span class="text-sm text-gray-500">'.e(__('employees.payroll.select_period')).'</span>');
        }

        try {
            $calculation = app(EmployeePayrollService::class)->calculate(
                $this->record,
                $get('source'),
                $get('period_start'),
                $get('period_end'),
            );
        } catch (ValidationException) {
            return new HtmlString('<span class="text-sm text-gray-500">'.e(__('employees.payroll.unavailable')).'</span>');
        }

        return new HtmlString('<div class="grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5 sm:grid-cols-4">'
            .'<span>'.e(__('employees.payroll.base_amount')).'<strong class="mt-1 block">'.e(Currency::format($calculation['base_amount'], $calculation['currency'])).'</strong></span>'
            .'<span>'.e(__('employees.payroll.gross_amount')).'<strong class="mt-1 block">'.e(Currency::format($calculation['gross_amount'], $calculation['currency'])).'</strong></span>'
            .'<span>'.e(__('employees.payroll.deductions')).'<strong class="mt-1 block">'.e(Currency::format($calculation['deductions'], $calculation['currency'])).'</strong></span>'
            .'<span>'.e(__('employees.payroll.net_amount')).'<strong class="mt-1 block text-primary-600">'.e(Currency::format($calculation['net_amount'], $calculation['currency'])).'</strong></span>'
            .(isset($calculation['required_amount']) ? '<span>'.e(__('employees.payroll.funding_required')).'<strong class="mt-1 block text-primary-600">'.e(Currency::format($calculation['required_amount'], $calculation['currency'])).'</strong></span>' : '')
            .'</div>');
    }
}
