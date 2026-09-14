<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\LabAdditionalWork;
use App\Models\LabCase;
use App\Models\LabMainWork;
use App\Services\EmployeePayrollService;
use App\Services\EmployeeSalaryService;
use App\Services\FinanceUsdUsageService;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewEmployee extends ViewRecord
{
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

    public function salaryHistoryAction(): Action
    {
        return Action::make('salaryHistory')->label(__('employees.salary.history'))->color('gray')
            ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('employees.salary.close'))
            ->modalContent(fn () => view('filament.resources.employees.salary-history', ['record' => $this->record]));
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

    public function calculateSalaryAction(): Action
    {
        return Action::make('calculateSalary')->label(__('employees.salary.calculate'))
            ->visible(fn (): bool => $this->record->is_active && $this->record->salary_active && filled($this->record->salary_type))
            ->modalWidth('5xl')->modalSubmitActionLabel(__('employees.salary.finalize'))->modalCancelActionLabel(__('employees.salary.close'))
            ->schema(function (): array {
                if ($this->record->salary_type === 'fixed') {
                    return [
                        TextInput::make('month')->label(__('employees.salary.month'))->default(now()->format('Y-m'))->required()->rules(['date_format:Y-m']),
                        Placeholder::make('monthly')->label(__('employees.salary.monthly'))->content(number_format((float) $this->record->monthly_salary_gel, 2).' ₾')->helperText(__('employees.salary.full_month')),
                    ];
                }

                return [
                    DatePicker::make('from')->label(__('employees.salary.from'))->native(false)->displayFormat('d.m.Y')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => $this->resetSelection($set, $get)),
                    DatePicker::make('until')->label(__('employees.salary.until'))->native(false)->displayFormat('d.m.Y')->afterOrEqual('from')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => $this->resetSelection($set, $get)),
                    CheckboxList::make('selected_items')->hiddenLabel()->live()->required()
                        ->options(fn (Get $get): array => $this->rows($get)->map(fn (array $row): string => $row['patient_name'])->all())
                        ->default(fn (): array => app(EmployeeSalaryService::class)->pending($this->record)->keys()->all())
                        ->view('filament.resources.employees.salary-items')
                        ->viewData(fn (Get $get): array => ['rows' => $this->rows($get)])
                        ->columnSpanFull(),
                    Placeholder::make('carry_breakdown')->hiddenLabel()->visible(fn (): bool => $this->openingCarry() > 0)
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<div class="flex flex-wrap gap-x-5 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">'
                            .'<span>'.__('employees.salary.previous_unpaid').': <strong>'.number_format($this->openingCarry(), 2).' ₾</strong></span>'
                            .'<span>'.__('employees.salary.current_salary').': <strong>'.number_format($this->currentSalary($get), 2).' ₾</strong></span>'
                            .'<span>'.__('employees.salary.total_due').': <strong>'.number_format($this->totalDue($get), 2).' ₾</strong></span>'
                            .'</div>',
                        ))->columnSpanFull(),
                    Grid::make(['default' => 1, 'sm' => 2, 'xl' => 4])->schema([
                        Placeholder::make('total')->label(__('employees.salary.total'))
                            ->content(fn (Get $get): string => number_format($this->totalDue($get), 2).' ₾'),
                        TextInput::make('clinic_cash_gel')->label(__('employees.salary.clinic_cash'))->numeric()->minValue(0)
                            ->maxValue(fn (): float => $this->cashBalance('clinic'))->step(0.01)->required()->suffix('GEL')->default(0)->live()
                            ->helperText(fn (): string => __('employees.salary.available', ['amount' => number_format($this->cashBalance('clinic'), 2)])),
                        TextInput::make('israeli_cash_gel')->label(__('employees.salary.israeli_cash'))->numeric()->minValue(0)
                            ->maxValue(fn (): float => $this->cashBalance('israeli'))->step(0.01)->required()->suffix('GEL')->default(0)->live()
                            ->helperText(fn (): string => __('employees.salary.available', ['amount' => number_format($this->cashBalance('israeli'), 2)])),
                        Placeholder::make('remaining')->label(__('employees.salary.remaining'))
                            ->content(function (Get $get): HtmlString {
                                $remaining = $this->remaining($get);
                                $class = $remaining > 0 ? 'font-semibold text-danger-600' : 'font-medium text-gray-700 dark:text-gray-200';

                                return new HtmlString('<span class="'.$class.'">'.number_format($remaining, 2).' ₾</span>');
                            }),
                    ])->columnSpanFull(),
                ];
            })->action(function (array $data): void {
                abort_unless(auth()->user()?->isOwner(), 403);
                $service = app(EmployeeSalaryService::class);
                if ($this->record->salary_type === 'fixed') {
                    $service->settleFixed($this->record, $data['month']);
                } else {
                    $service->settle($this->record, $data['selected_items'], $data['from'] ?? null, $data['until'] ?? null, $data);
                }
                $this->record->refresh();
                Notification::make()->title(__('employees.salary.saved'))->success()->send();
            });
    }

    public function undoSalaryAction(): Action
    {
        return Action::make('undoSalary')->label(__('employees.salary.undo'))->color('danger')->requiresConfirmation()
            ->action(function (array $arguments): void {
                abort_unless(auth()->user()?->isOwner(), 403);
                $settlement = $this->record->salarySettlements()->findOrFail($arguments['settlement'] ?? null);
                app(EmployeeSalaryService::class)->undo($settlement);
                $this->record->refresh();
                Notification::make()->title(__('employees.salary.undone'))->success()->send();
            });
    }

    private function rows(Get $get): Collection
    {
        // An inverted draft range should remain editable without a render-time exception.
        if ($get('from') && $get('until') && $get('from') > $get('until')) {
            return collect();
        }

        return app(EmployeeSalaryService::class)->pending($this->record, $get('from'), $get('until'));
    }

    private function resetSelection(Set $set, Get $get): void
    {
        $set('selected_items', $this->rows($get)->keys()->all());
    }

    private function cashBalance(string $source): float
    {
        return max(0, app(FinanceUsdUsageService::class)->cashBalances($source)['GEL']);
    }

    private function openingCarry(): float
    {
        return app(EmployeeSalaryService::class)->openingCarry($this->record);
    }

    private function currentSalary(Get $get): float
    {
        return round((float) $this->rows($get)->only($get('selected_items') ?? [])->sum('amount_gel'), 2);
    }

    private function totalDue(Get $get): float
    {
        return round($this->openingCarry() + $this->currentSalary($get), 2);
    }

    private function remaining(Get $get): float
    {
        return round($this->totalDue($get)
            - (float) ($get('clinic_cash_gel') ?? 0)
            - (float) ($get('israeli_cash_gel') ?? 0), 2);
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
