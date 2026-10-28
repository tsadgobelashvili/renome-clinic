<?php

namespace App\Filament\Resources\PartnerFinance\Pages;

use App\Filament\Actions\IsraeliPatientPaymentAction;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Models\Doctor;
use App\Models\User;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliMoneyMovements;
use App\Services\PartnerFinanceSummary;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ListPartnerFinance extends ListRecords
{
    protected static string $resource = PartnerFinanceResource::class;

    public string $period = '7_days';

    public string $dateFrom = '';

    public string $dateUntil = '';

    public string $movementType = '';

    public function mount(): void
    {
        parent::mount();
        $this->applyPeriod('7_days');
    }

    public function updatedPeriod(string $period): void
    {
        if ($period !== 'custom') {
            $this->applyPeriod($period);
        }

        $this->resetTable();
        $this->resetPage(pageName: 'israeliMovementPage');
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
        $this->resetTable();
        $this->resetPage(pageName: 'israeliMovementPage');
    }

    public function updatedDateUntil(): void
    {
        $this->period = 'custom';
        $this->resetTable();
        $this->resetPage(pageName: 'israeliMovementPage');
    }

    public function updatedMovementType(): void
    {
        $this->resetTable();
    }

    public function overview(): array
    {
        $summary = app(PartnerFinanceSummary::class);
        $report = app(IsraeliMoneyMovements::class);
        $expense = $summary->expenseTotals($this->dateFrom, $this->dateUntil);

        return [
            'cash' => $summary->currentCashTotals(),
            'income' => $summary->receivedTotals($this->dateFrom, $this->dateUntil),
            'expense' => $expense,
            'expense_funding' => $report->expenseFunding($this->dateFrom, $this->dateUntil, $expense),
            'movements' => array_values(array_filter($report->totals($this->dateFrom, $this->dateUntil),
                fn ($row) => in_array($row['key'], ['bank_deposit', 'exchange', 'owner_withdrawal'], true) || $row['count'] > 0)),
        ];
    }

    private function applyPeriod(string $period): void
    {
        $this->dateUntil = today()->toDateString();
        $this->dateFrom = match ($period) {
            '1_month' => today()->subMonth()->toDateString(),
            '3_months' => today()->subMonths(3)->toDateString(),
            '1_year' => today()->subYear()->toDateString(),
            default => today()->subDays(6)->toDateString(),
        };
    }

    public function movementDetailsAction(): Action
    {
        return Action::make('movementDetails')
            ->authorize(fn () => PartnerFinanceResource::canViewAny())
            ->modalHeading(fn (array $arguments) => IsraeliMoneyMovements::LABELS[$arguments['movement'] ?? ''] ?? 'ფულის მოძრაობა')
            ->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('დახურვა')
            ->mountUsing(fn () => $this->resetPage(pageName: 'israeliMovementPage'))
            ->modalContent(fn (array $arguments) => view('filament.resources.partner-finance.movement-details', $this->movementDetails($arguments)));
    }

    public function movementDetails(array $arguments): array
    {
        abort_unless(PartnerFinanceResource::canViewAny(), 403);
        $key = $arguments['movement'] ?? '';
        abort_unless(is_string($key) && isset(IsraeliMoneyMovements::LABELS[$key]), 404);
        $report = app(IsraeliMoneyMovements::class);

        return ['movement' => $report->totals($this->dateFrom, $this->dateUntil)[$key],
            'from' => $this->dateFrom, 'until' => $this->dateUntil,
            'transactions' => $report->query($this->dateFrom, $this->dateUntil, $key)
                ->orderByDesc('transacted_at')->orderByDesc('id')
                ->simplePaginate(25, pageName: 'israeliMovementPage')];
    }

    protected function getHeaderActions(): array
    {
        $exchangeMode = fn (Get $get): bool => $get('payment_mode') === 'exchange_usd_gel';

        return [
            IsraeliPatientPaymentAction::make(),
            Action::make('useIsraeliFunds')
                ->label('თანხის გამოყენება')
                ->color('primary')
                ->modalHeading('თანხის გამოყენება')
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('დაფიქსირება')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        DateTimePicker::make('transacted_at')->label('თარიღი / დრო')->default(now())
                            ->required(),
                        Select::make('operation_type')->label('ოპერაციის ტიპი')->options([
                            'doctor_salary' => 'ექიმის ხელფასი',
                            'lab_salary' => 'ლაბორატორიის ხელფასი',
                            'bank_deposit' => 'ანგარიშზე შეტანა',
                            'materials' => 'მასალები / მარაგები',
                            'equipment' => 'აღჭურვილობა',
                            'owner_withdrawal' => 'მფლობელის გატანა',
                            'other' => 'სხვა',
                        ])->live()->native(false)->required(),
                        Select::make('payment_mode')->label('გადახდის რეჟიმი')->options([
                            'direct_gel' => 'GEL',
                            'direct_usd' => 'USD',
                            'exchange_usd_gel' => 'USD → GEL გაცვლა',
                        ])->default('exchange_usd_gel')->live()->native(false)
                            ->required(),
                    ]),
                    ...array_map(fn ($field) => $field->visible(fn (Get $get): bool => in_array($get('operation_type'), ['materials', 'equipment', 'other'], true)), ExpenseCategoryForm::schema()),
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        TextInput::make('usd_amount')->label('გასაცვლელი USD')->numeric()->minValue(0.01)->step(0.01)->prefix('$')
                            ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncExchange($get, $set))
                            ->visible($exchangeMode)->required($exchangeMode),
                        TextInput::make('exchange_rate')->label('გაცვლის კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                            ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncExchange($get, $set))
                            ->visible($exchangeMode)->required($exchangeMode),
                        TextInput::make('received_gel_amount')->label('მიღებული GEL')->numeric()->readOnly()->suffix('₾')
                            ->visible($exchangeMode)->dehydrated(false),
                        TextInput::make('actual_amount')
                            ->label(fn (Get $get): string => $get('payment_mode') === 'direct_usd' ? 'გამოყენებული USD' : 'რეალურად გამოყენებული GEL')
                            ->numeric()->minValue(0)->step(0.01)
                            ->prefix(fn (Get $get): ?string => $get('payment_mode') === 'direct_usd' ? '$' : null)
                            ->suffix(fn (Get $get): ?string => in_array($get('payment_mode'), ['direct_gel', 'exchange_usd_gel'], true) ? '₾' : null)
                            ->live()->required(),
                    ]),
                    Placeholder::make('exchange_summary')->label('გაცვლის შედეგი')
                        ->content(function (Get $get): string {
                            $received = FinanceUsdUsageService::calculateReceivedGel($get('usd_amount'), $get('exchange_rate'));
                            $used = round((float) ($get('actual_amount') ?? 0), 2);

                            return 'მიღებული: '.Currency::format($received, 'GEL')
                                .' · გამოყენებული: '.Currency::format($used, 'GEL')
                                .' · დარჩენილი: '.Currency::format($received - $used, 'GEL');
                        })->visible($exchangeMode),
                    Select::make('recipient')->label('ექიმი')
                        ->options(fn (): array => Doctor::query()->where('is_active', true)->orderBy('first_name')->get()
                            ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->full_name => $doctor->full_name])->all())
                        ->searchable()->native(false)
                        ->visible(fn (Get $get): bool => $get('operation_type') === 'doctor_salary')
                        ->required(fn (Get $get): bool => $get('operation_type') === 'doctor_salary'),
                    Select::make('lab_recipient')->label('ლაბორანტი (არასავალდებულო)')
                        ->options(fn (): array => User::query()->where('role', User::ROLE_LAB_TECHNICIAN)->where('is_active', true)
                            ->orderBy('name')->pluck('name', 'name')->all())
                        ->searchable()->native(false)
                        ->visible(fn (Get $get): bool => $get('operation_type') === 'lab_salary'),
                    TextInput::make('other_recipient')->label('მიმღები / დასახელება (არასავალდებულო)')->maxLength(255)
                        ->visible(fn (Get $get): bool => ! in_array($get('operation_type'), ['doctor_salary', 'lab_salary'], true)),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2)->columnSpanFull(),
                ])
                ->mutateDataUsing(function (array $data): array {
                    $data['recipient'] = $data['recipient'] ?? $data['lab_recipient'] ?? $data['other_recipient'] ?? null;

                    return $data;
                })
                ->action(function (array $data, FinanceUsdUsageService $service): void {
                    $service->recordIsraeliOperation($data);
                }),
        ];
    }

    private static function syncExchange(Get $get, Set $set): void
    {
        $set('received_gel_amount', FinanceUsdUsageService::calculateReceivedGel($get('usd_amount'), $get('exchange_rate')));
    }
}
