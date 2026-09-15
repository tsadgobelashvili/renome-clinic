<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Support\ProductSaleForm;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Services\FinanceManager;
use App\Services\ProductSaleService;
use App\Support\CashboxExpenseForm;
use App\Support\CashboxManager;
use App\Support\CashboxMovementPresentation;
use App\Support\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class Cashbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cashbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'სალარო';

    protected static ?string $title = 'სალარო';

    protected static ?int $navigationSort = 30;

    public CashboxDay $day;

    public function mount(CashboxManager $manager): void
    {
        $requestedDate = request()->query('date');
        $requestedDay = is_string($requestedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $requestedDate)
            ? CashboxDay::query()->whereDate('date', $requestedDate)->first()
            : null;

        $this->day = $requestedDay?->status === 'closed'
            ? $requestedDay
            : $manager->oldestUnclosedDay();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CashboxTransaction::query()
                ->with(['financeTransaction.expenseCategory', 'financeTransaction.expenseSubcategory'])
                ->where('cashbox_day_id', $this->day->getKey()))
            ->columns([
                TextColumn::make('transaction_date')->label('დრო')->dateTime('H:i')->sortable()
                    ->timezone(config('app.timezone'))
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('type')->label('ტიპი')->badge()
                    ->formatStateUsing(fn (CashboxTransaction $record): string => CashboxMovementPresentation::type($record))
                    ->color(fn (CashboxTransaction $record): string => CashboxMovementPresentation::color($record)),
                TextColumn::make('category')->label('კატეგორია')
                    ->state(fn (CashboxTransaction $record): string => CashboxMovementPresentation::category($record))
                    ->limit(40)->tooltip(fn (CashboxTransaction $record): string => CashboxMovementPresentation::category($record)),
                TextColumn::make('description')->label('აღწერა')
                    ->state(fn (CashboxTransaction $record): string => CashboxMovementPresentation::description($record))
                    ->limit(60)->tooltip(fn (CashboxTransaction $record): string => CashboxMovementPresentation::description($record)),
                TextColumn::make('payment_method')->label('მეთოდი')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? PaymentMethod::labelFor($state) : '—')
                    ->color('gray'),
                TextColumn::make('amount')->label('თანხა')
                    ->state(fn (CashboxTransaction $record): string => CashboxMovementPresentation::sign($record).Currency::format($record->amount, $record->currency))
                    ->color(fn (CashboxTransaction $record): string => CashboxMovementPresentation::color($record))
                    ->alignEnd()->weight('semibold')->extraCellAttributes(['class' => 'whitespace-nowrap']),
            ])
            ->filters([
                SelectFilter::make('type')->label('ტიპი')->options(CashboxTransaction::TYPE_LABELS),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->striped()
            ->paginationPageOptions([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openingBalance')->label('საწყისი ნაშთი')->color('gray')
                ->disabled(fn (): bool => $this->day->status === 'closed')
                ->schema([
                    TextInput::make('opening_balance')->label('დასამატებელი GEL')->numeric()->minValue(0)->default(0)->suffix('₾'),
                    TextInput::make('opening_balance_usd')->label('დასამატებელი USD')->numeric()->minValue(0)->default(0)->prefix('$'),
                ])
                ->action(function (array $data, CashboxManager $manager): void {
                    $manager->addOpeningBalance($this->day, (float) ($data['opening_balance'] ?? 0), (float) ($data['opening_balance_usd'] ?? 0));
                    $this->refreshDay('საწყისი ნაშთი განახლდა.');
                }),
            Action::make('expense')->label('+ ახალი ხარჯი')->color('danger')
                ->disabled(fn (): bool => $this->day->status === 'closed' || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
                ->schema(CashboxExpenseForm::schema())
                ->action(function (array $data, FinanceManager $finance): void {
                    $finance->create([
                        ...$data, 'type' => 'expense',
                        'payment_method' => 'cash', 'cash_source' => 'current_cashier',
                    ]);
                    $this->refreshDay('ხარჯი დაემატა.');
                }),
            Action::make('productSale')->label('პროდუქტის გაყიდვა')->color('gray')->size('sm')
                ->modalHeading('პროდუქტის გაყიდვა')->modalWidth('4xl')->modalSubmitActionLabel('გაყიდვა')
                ->disabled(fn (): bool => $this->day->status === 'closed' || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
                ->schema(ProductSaleForm::schema())
                ->action(function (array $data, ProductSaleService $sales): void {
                    $sales->create($data);
                    $this->refreshDay('პროდუქტის გაყიდვა დაფიქსირდა.');
                }),
            Action::make('withdrawal')->label('ქეშის ამოღება')->color('warning')
                ->disabled(fn (): bool => $this->day->status === 'closed' || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
                ->schema([
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->required()->suffix('₾'),
                    Textarea::make('description')->label('კომენტარი')->rows(2),
                ])
                ->action(function (array $data): void {
                    $available = $this->day->summary()['expected'];
                    if ((float) $data['amount'] > $available) {
                        throw ValidationException::withMessages(['amount' => 'ამოსაღები თანხა მოსალოდნელ ნაღდ ნაშთს ვერ გადააჭარბებს.']);
                    }
                    $this->day->transactions()->create([...$data, 'type' => 'cash_withdrawal', 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()]);
                    $this->refreshDay('ქეშის ამოღება დაფიქსირდა.');
                }),
            Action::make('closeDay')->label(fn (): string => 'დღის დახურვა '.$this->day->date->format('d.m.Y'))->color('success')
                ->modalHeading(fn (): string => 'დღის დახურვა '.$this->day->date->format('d.m.Y'))
                ->modalDescription(fn (): string => 'დაადასტურეთ '.$this->day->date->format('d.m.Y').' დღის სალაროს დახურვა.')
                ->disabled(fn (): bool => $this->day->status === 'closed')
                ->schema([
                    TextInput::make('actual_closing_balance')->label('ფაქტობრივი ნაღდი ნაშთი')->numeric()->minValue(0)->required()->suffix('₾')->default(fn () => $this->day->summary()['expected']),
                    TextInput::make('actual_closing_balance_usd')->label('ფაქტობრივი USD ნაშთი')->numeric()->minValue(0)->required()->prefix('$')->default(fn () => $this->day->summary()['expectedByCurrency']['USD']),
                    TextInput::make('carry_forward_balance')->label('მომდევნო დღისთვის დასატოვებელი')->numeric()->minValue(0)->required()->suffix('₾')->default(0),
                    TextInput::make('carry_forward_balance_usd')->label('მომდევნო დღისთვის USD')->numeric()->minValue(0)->required()->prefix('$')->default(0),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2),
                ])
                ->action(function (array $data, CashboxManager $manager): void {
                    $manager->close(
                        $this->day,
                        (float) $data['actual_closing_balance'],
                        (float) $data['carry_forward_balance'],
                        $data['notes'] ?? null,
                        (float) $data['actual_closing_balance_usd'],
                        (float) $data['carry_forward_balance_usd'],
                    );

                    $this->day = $manager->oldestUnclosedDay();

                    $this->refreshDay('სალაროს დღე დაიხურა.');
                }),
        ];
    }

    private function transactionSchema(bool $expense = false): array
    {
        return [
            TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->required()->suffix('₾'),
            Select::make('payment_method')->label('გადახდის მეთოდი')->options(['cash' => 'ნაღდი', 'card' => 'ბარათი'])->required()->default('cash'),
            ...($expense ? [Select::make('expense_category')->label('კატეგორია')->options([
                'materials' => 'მასალები', 'transport' => 'ტრანსპორტი', 'utilities' => 'კომუნალური',
                'office' => 'ოფისი', 'salary_advance' => 'ხელფასი / ავანსი', 'other' => 'სხვა',
            ])->required()] : []),
            DateTimePicker::make('transaction_date')->label('თარიღი / დრო')->timezone(config('app.timezone'))->required()->default(now()),
            Textarea::make('description')->label('კომენტარი')->rows(2),
        ];
    }

    private function refreshDay(string $message): void
    {
        $this->day->refresh();
        $this->resetTable();
        Notification::make()->success()->title($message)->send();
    }

    protected function getViewData(): array
    {
        $manager = app(CashboxManager::class);
        $manager->ensureCalendarDaysThroughToday();

        $historyDays = CashboxDay::query()
            ->with([
                'closer',
                'transactions.financeTransaction.expenseCategory',
                'transactions.financeTransaction.expenseSubcategory',
            ])
            ->latest('date')
            ->limit(14)
            ->get();
        $totals = $manager->summaryTotals($historyDays->concat([$this->day])->unique('id'));

        return [
            'summary' => $manager->summary($this->day, $totals->get($this->day->id, collect())),
            'unresolvedPreviousDay' => $manager->unresolvedPreviousDay(),
            'history' => $historyDays
                ->map(fn (CashboxDay $day): array => [
                    'day' => $day,
                    'summary' => $manager->summary($day, $totals->get($day->id, collect())),
                    'transactions' => $this->historyTransactions($day),
                ]),
        ];
    }

    /** @return Collection<int, array{transaction: CashboxTransaction, amount_display: string}> */
    private function historyTransactions(CashboxDay $day): Collection
    {
        return $day->transactions
            ->sortByDesc('transaction_date')
            ->groupBy(fn (CashboxTransaction $transaction): string => $transaction->type === 'patient_payment' && filled($transaction->payment_id)
                ? 'payment-'.$transaction->payment_id.'-'.$transaction->payment_method
                : 'transaction-'.$transaction->getKey())
            ->map(function (Collection $transactions): array {
                /** @var CashboxTransaction $transaction */
                $transaction = $transactions->first();

                return [
                    'transaction' => $transaction,
                    'amount_display' => $transactions
                        ->map(fn (CashboxTransaction $row): string => Currency::format($row->amount, $row->currency))
                        ->implode(' + '),
                ];
            })
            ->values();
    }
}
