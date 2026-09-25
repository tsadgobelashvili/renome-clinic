<?php

namespace App\Filament\Pages\Concerns;

use App\Services\EmployeeSalaryService;
use App\Services\FinanceUsdUsageService;
use App\Support\TechnicianSalaryReview;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

trait InteractsWithTechnicianSalary
{
    public int $salaryReviewPage = 1;

    public ?string $salaryReviewGroup = null;

    public int $salaryReviewDetailPage = 1;

    // Protected properties live only for this request, never a persistent financial cache.
    protected array $salaryRowsCache = [];

    protected array $salaryGroupsCache = [];

    public function salaryHistoryAction(): Action
    {
        return Action::make('salaryHistory')->label(__('employees.salary.history'))->color('gray')
            ->visible(fn (): bool => $this->record !== null)
            ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('employees.salary.close'))
            ->modalContent(fn () => view('filament.resources.employees.salary-history', ['record' => $this->record]));
    }

    protected function salaryPeriodFrom(): ?string
    {
        return null;
    }

    protected function salaryPeriodUntil(): ?string
    {
        return null;
    }

    public function monthlySalaryAction(): Action
    {
        return Action::make('monthlySalary')->label(__('employees.salary.monthly_action'))
            ->visible(fn (): bool => (auth()->user()?->isOwner() ?? false) && $this->record?->is_active && $this->record->salary_active && $this->record->salary_type === 'combined')
            ->schema([
                \Filament\Forms\Components\Select::make('month')->label(__('employees.salary.month'))
                    ->options(fn (): array => collect(app(EmployeeSalaryService::class)->monthlySchedule($this->record)['due'])->mapWithKeys(fn (array $entry) => [$entry['month'] => $entry['month']])->all())
                    ->default(fn (): ?string => app(EmployeeSalaryService::class)->monthlySchedule($this->record)['due'][0]['month'] ?? null)
                    ->required()->native(false),
                Placeholder::make('monthly')->label(__('employees.salary.monthly'))
                    ->content(fn (): string => number_format((float) $this->record->monthly_salary_gel, 2).' GEL'),
            ])
            ->modalSubmitActionLabel(__('employees.salary.finalize'))
            ->action(function (array $data): void {
                abort_unless(auth()->user()?->isOwner() && $this->record->salary_type === 'combined', 403);
                app(EmployeeSalaryService::class)->settleFixed($this->record, $data['month']);
                $this->record->refresh();
                Notification::make()->title(__('employees.salary.saved'))->success()->send();
            });
    }

    public function calculateSalaryAction(): Action
    {
        return Action::make('calculateSalary')->label(__('employees.salary.calculate'))
            ->beforeFormFilled(fn () => $this->resetSalaryReview())
            ->visible(fn (): bool => $this->record?->is_active && $this->record->salary_active && filled($this->record->salary_type))
            ->modalWidth('5xl')->modalSubmitActionLabel(__('employees.salary.finalize'))->modalCancelActionLabel(__('employees.salary.close'))
            ->schema(function (): array {
                if ($this->record->salary_type === 'fixed') {
                    return [
                        TextInput::make('month')->label(__('employees.salary.month'))->default(now()->format('Y-m'))->required()->rules(['date_format:Y-m']),
                        Placeholder::make('monthly')->label(__('employees.salary.monthly'))->content(number_format((float) $this->record->monthly_salary_gel, 2).' ₾')->helperText(__('employees.salary.full_month')),
                    ];
                }

                return [
                    DatePicker::make('from')->default(fn () => $this->salaryPeriodFrom())->label(__('employees.salary.from'))->native(false)->displayFormat('d.m.Y')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => $this->resetSelection($set, $get)),
                    DatePicker::make('until')->default(fn () => $this->salaryPeriodUntil())->label(__('employees.salary.until'))->native(false)->displayFormat('d.m.Y')->afterOrEqual('from')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => $this->resetSelection($set, $get)),
                    CheckboxList::make('selected_items')->hiddenLabel()->live()->required()
                        ->options(fn (Get $get): array => $this->rows($get)->map(fn (array $row): string => $row['patient_name'])->all())
                        ->default(fn (): array => $this->eligibleSalaryRows($this->salaryPeriodFrom(), $this->salaryPeriodUntil())->keys()->all())
                        ->view('filament.resources.employees.salary-items')
                        ->viewData(fn (Get $get): array => $this->salaryReviewData($get))
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
                $this->resetSalaryReview();
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
                $this->resetSalaryReview();
                Notification::make()->title(__('employees.salary.undone'))->success()->send();
            });
    }

    private function rows(Get $get): Collection
    {
        // An inverted draft range should remain editable without a render-time exception.
        if ($get('from') && $get('until') && $get('from') > $get('until')) {
            return collect();
        }

        return $this->eligibleSalaryRows($get('from'), $get('until'));
    }

    private function eligibleSalaryRows(?string $from = null, ?string $until = null): Collection
    {
        $key = json_encode([$this->record->id, $from, $until]);

        return $this->salaryRowsCache[$key] ??= app(EmployeeSalaryService::class)->pending($this->record, $from, $until);
    }

    private function salaryReviewGroups(?string $from, ?string $until): Collection
    {
        if ($from && $until && $from > $until) {
            return collect();
        }
        $key = json_encode([$this->record->id, $from, $until]);

        return $this->salaryGroupsCache[$key] ??= app(TechnicianSalaryReview::class)->groups(
            $this->record->id, $this->eligibleSalaryRows($from, $until),
        );
    }

    private function salaryReviewData(Get $get): array
    {
        $groups = $this->salaryReviewGroups($get('from'), $get('until'));
        $pages = max(1, (int) ceil($groups->count() / TechnicianSalaryReview::GROUPS_PER_PAGE));
        $page = min(max(1, $this->salaryReviewPage), $pages);

        return ['groups' => $groups->forPage($page, TechnicianSalaryReview::GROUPS_PER_PAGE),
            'groupCount' => $groups->count(), 'page' => $page, 'pages' => $pages,
            'expandedGroup' => $this->salaryReviewGroup, 'detailPage' => $this->salaryReviewDetailPage];
    }

    public function setSalaryReviewPage(int $page): void
    {
        abort_unless($this->getMountedAction()?->getName() === 'calculateSalary', 403);
        $this->salaryReviewPage = max(1, $page);
        $this->salaryReviewGroup = null;
        $this->salaryReviewDetailPage = 1;
    }

    public function toggleSalaryReviewGroup(string $key): void
    {
        abort_unless($this->getMountedAction()?->getName() === 'calculateSalary', 403);
        $this->salaryReviewGroup = $this->salaryReviewGroup === $key ? null : $key;
        $this->salaryReviewDetailPage = 1;
    }

    public function setSalaryReviewDetailPage(int $page): void
    {
        abort_unless($this->getMountedAction()?->getName() === 'calculateSalary', 403);
        $this->salaryReviewDetailPage = max(1, $page);
    }

    public function toggleSalaryReviewSelection(string $key): void
    {
        abort_unless($this->getMountedAction()?->getName() === 'calculateSalary', 403);
        $form = $this->getMountedActionSchema();
        $state = $form->getRawState();
        $group = $this->salaryReviewGroups($form->getComponentByStatePath('from')?->getState(), $form->getComponentByStatePath('until')?->getState())->get($key);
        if (! $group) {
            return;
        }
        $keys = array_keys($group['items']);
        $selected = (array) ($state['selected_items'] ?? []);
        $allSelected = array_diff($keys, $selected) === [];
        data_set($this, $form->getStatePath().'.selected_items', $allSelected
            ? array_values(array_diff($selected, $keys)) : array_values(array_unique([...$selected, ...$keys])));
    }

    private function resetSalaryReview(): void
    {
        $this->salaryReviewPage = $this->salaryReviewDetailPage = 1;
        $this->salaryReviewGroup = null;
        $this->salaryRowsCache = $this->salaryGroupsCache = [];
    }

    private function resetSelection(Set $set, Get $get): void
    {
        $this->resetSalaryReview();
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
}
