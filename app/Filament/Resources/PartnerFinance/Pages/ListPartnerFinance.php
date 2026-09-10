<?php

namespace App\Filament\Resources\PartnerFinance\Pages;

use App\Filament\Actions\IsraeliPatientPaymentAction;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Models\Doctor;
use App\Models\PartnerFinanceTransaction;
use App\Models\User;
use App\Services\FinanceUsdUsageService;
use App\Services\PartnerFinanceSummary;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
use Carbon\Carbon;
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
use Illuminate\Support\Collection;

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
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
        $this->resetTable();
    }

    public function updatedDateUntil(): void
    {
        $this->period = 'custom';
        $this->resetTable();
    }

    public function updatedMovementType(): void
    {
        $this->resetTable();
    }

    public function overview(): array
    {
        $summary = app(PartnerFinanceSummary::class);
        $movements = $this->movementRecords();
        $expense = $summary->expenseTotals($this->dateFrom, $this->dateUntil);

        return [
            'cash' => $summary->currentCashTotals(),
            'income' => $summary->receivedTotals($this->dateFrom, $this->dateUntil),
            'expense' => $expense,
            'expense_funding' => $this->expenseFundingSummary($movements, $expense),
            'movements' => $this->movementSummary($movements),
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

    /** @return Collection<int, PartnerFinanceTransaction> */
    private function movementRecords(): Collection
    {
        return PartnerFinanceTransaction::query()
            ->israeli()
            ->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
            ])
            ->whereDate('transacted_at', '>=', $this->dateFrom)
            ->whereDate('transacted_at', '<=', $this->dateUntil)
            ->latest('transacted_at')
            ->get();
    }

    private function movementSummary(Collection $movements): array
    {
        return $movements
            ->groupBy(fn (PartnerFinanceTransaction $movement): string => $movement->transacted_at
                ->timezone(config('app.timezone'))->toDateString())
            ->take(3)
            ->map(fn (Collection $items, string $date): array => [
                'date' => Carbon::parse($date)->format('d.m.Y'),
                'movements' => $items->map(fn (PartnerFinanceTransaction $movement): array => [
                    'label' => match ($movement->type) {
                        PartnerFinanceTransaction::TYPE_EXCHANGE => 'გაცვლა',
                        PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL => 'მფლობელის გატანა',
                        default => PartnerFinanceTransaction::TRANSFER_CATEGORIES[$movement->category] ?? 'ტრანსფერი',
                    },
                    'display' => $movement->type === PartnerFinanceTransaction::TYPE_EXCHANGE
                        ? Currency::format($movement->from_amount, $movement->from_currency).' → '.Currency::format($movement->to_amount, $movement->to_currency)
                        : Currency::format($movement->amount, $movement->currency),
                ])->values()->all(),
            ])->values()->all();
    }

    private function expenseFundingSummary(Collection $movements, array $expenseTotals): array
    {
        $exchanges = $movements
            ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)
            ->filter(fn (PartnerFinanceTransaction $exchange): bool => $exchange->from_currency === 'USD'
                && $exchange->to_currency === 'GEL');

        if ($exchanges->isEmpty()) {
            return ['direct_gel' => (float) $expenseTotals['GEL'], 'exchanged_usd' => 0.0, 'funded_gel' => 0.0];
        }

        $expenses = PartnerFinanceTransaction::query()
            ->israeli()
            ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
            ->where('currency', 'GEL')
            ->whereDate('transacted_at', '>=', $this->dateFrom)
            ->whereDate('transacted_at', '<=', $this->dateUntil)
            ->get()
            ->groupBy(fn (PartnerFinanceTransaction $expense): string => $this->operationSignature($expense));

        $linkedExchanges = $exchanges->filter(fn (PartnerFinanceTransaction $exchange): bool => $expenses
            ->has($this->operationSignature($exchange)));
        $fundedGel = (float) $linkedExchanges->sum(fn (PartnerFinanceTransaction $exchange): float => (float) $expenses
            ->get($this->operationSignature($exchange), collect())->sum('amount'));

        return [
            'direct_gel' => round(max((float) $expenseTotals['GEL'] - $fundedGel, 0), 2),
            'exchanged_usd' => round((float) $linkedExchanges->sum('from_amount'), 2),
            'funded_gel' => round($fundedGel, 2),
        ];
    }

    private function operationSignature(PartnerFinanceTransaction $transaction): string
    {
        return $transaction->transacted_at->format('Y-m-d H:i:s.u').'|'.($transaction->created_by ?? 'system');
    }

    protected function getHeaderActions(): array
    {
        $exchangeMode = fn (Get $get): bool => $get('payment_mode') === 'exchange_usd_gel';

        return [
            IsraeliPatientPaymentAction::make(),
            Action::make('useIsraeliFunds')
                ->label('თანხის გამოყენება')
                ->color('primary')
                ->modalHeading('ისრაელის თანხის გამოყენება')
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('დაფიქსირება')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        DateTimePicker::make('transacted_at')->label('თარიღი / დრო')->default(now())->required(),
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
                            'direct_gel' => 'პირდაპირი GEL',
                            'direct_usd' => 'პირდაპირი USD',
                            'exchange_usd_gel' => 'USD → GEL გაცვლა',
                        ])->default('exchange_usd_gel')->live()->native(false)->required(),
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
                ->action(fn (array $data, FinanceUsdUsageService $service) => $service->recordIsraeliOperation($data)),
        ];
    }

    private static function syncExchange(Get $get, Set $set): void
    {
        $set('received_gel_amount', FinanceUsdUsageService::calculateReceivedGel($get('usd_amount'), $get('exchange_rate')));
    }
}
