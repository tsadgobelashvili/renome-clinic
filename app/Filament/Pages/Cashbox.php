<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Support\CashboxManager;
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
        $this->day = $manager->unresolvedPreviousDay() ?? $manager->today();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CashboxTransaction::query()
                ->with(['patient', 'visit.doctor', 'payment.splits'])
                ->where('cashbox_day_id', $this->day->getKey()))
            ->columns([
                TextColumn::make('transaction_date')->label('თარიღი / დრო')->dateTime('d.m.y H:i')->sortable()
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('type')->label('ტიპი')->badge()
                    ->formatStateUsing(fn (string $state, CashboxTransaction $record): string => $state === 'patient_payment'
                        && $record->visit?->visit_type === 'consultation'
                            ? 'კონსულტაცია / ტომოგრაფია'
                            : (CashboxTransaction::TYPE_LABELS[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'patient_payment' => 'success',
                        'expense' => 'danger',
                        'cash_withdrawal' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (CashboxTransaction $record): ?string => filled($record->description)
                        ? str($record->description)->limit(28)->toString()
                        : null),
                TextColumn::make('patient.full_name')->label('პაციენტი')->placeholder('—')->searchable(['first_name', 'last_name']),
                TextColumn::make('doctor_name')->label('ექიმი')
                    ->state(fn (CashboxTransaction $record): ?string => $record->visit?->doctor?->full_name)
                    ->placeholder('—'),
                TextColumn::make('cash_amount')->label('ნაღდი')
                    ->state(fn (CashboxTransaction $record): ?string => $this->paymentSplitAmount($record, 'cash'))
                    ->placeholder('—')->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('card_amount')->label('ბარათი')
                    ->state(fn (CashboxTransaction $record): ?string => $this->paymentSplitAmount($record, 'card'))
                    ->placeholder('—')->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('total_amount')->label('სულ')
                    ->state(fn (CashboxTransaction $record): string => Currency::format($record->amount, $record->currency))
                    ->weight('semibold')->extraCellAttributes(['class' => 'whitespace-nowrap']),
            ])
            ->filters([
                SelectFilter::make('type')->label('ტიპი')->options(CashboxTransaction::TYPE_LABELS),
                SelectFilter::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options()),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->recordActions([
                Action::make('openVisit')
                    ->label('ვიზიტის გახსნა')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('ვიზიტის გახსნა')
                    ->url(fn (CashboxTransaction $record): ?string => filled($record->visit_id)
                        ? VisitResource::getUrl('edit', ['record' => $record->visit_id])
                        : null)
                    ->visible(fn (CashboxTransaction $record): bool => filled($record->visit_id)),
            ])
            ->recordActionsAlignment('end')
            ->paginationPageOptions([10, 25, 50]);
    }

    private function paymentSplitAmount(CashboxTransaction $transaction, string $method): ?string
    {
        if ($transaction->type !== 'patient_payment' || ! $transaction->payment) {
            return null;
        }

        $amount = (float) $transaction->payment->splits
            ->where('payment_method', $method)
            ->sum('amount');

        return $amount > 0 ? Currency::format($amount, $transaction->currency) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openingBalance')->label('საწყისი ნაშთი')->color('gray')
                ->disabled(fn (): bool => $this->day->status === 'closed' || $this->day->transactions()->exists())
                ->schema([TextInput::make('opening_balance')->label('საწყისი ნაშთი')->numeric()->minValue(0)->required()->suffix('₾')->default($this->day->opening_balance)])
                ->action(function (array $data): void {
                    $this->day->update(['opening_balance' => $data['opening_balance']]);
                    $this->refreshDay('საწყისი ნაშთი განახლდა.');
                }),
            Action::make('expense')->label('+ ახალი ხარჯი')->color('danger')
                ->disabled(fn (): bool => $this->day->status === 'closed' || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
                ->schema($this->transactionSchema(expense: true))
                ->action(function (array $data): void {
                    $this->day->transactions()->create([...$data, 'type' => 'expense', 'currency' => 'GEL']);
                    $this->refreshDay('ხარჯი დაემატა.');
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
            Action::make('closeDay')->label('დღის დახურვა')->color('success')
                ->disabled(fn (): bool => $this->day->status === 'closed')
                ->schema([
                    TextInput::make('actual_closing_balance')->label('ფაქტობრივი ნაღდი ნაშთი')->numeric()->minValue(0)->required()->suffix('₾')->default(fn () => $this->day->summary()['expected']),
                    TextInput::make('carry_forward_balance')->label('მომდევნო დღისთვის დასატოვებელი')->numeric()->minValue(0)->required()->suffix('₾')->default(0),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2),
                ])
                ->action(function (array $data, CashboxManager $manager): void {
                    $manager->close($this->day, (float) $data['actual_closing_balance'], (float) $data['carry_forward_balance'], $data['notes'] ?? null);
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
            DateTimePicker::make('transaction_date')->label('თარიღი / დრო')->required()->default(now()),
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
        return [
            'summary' => $this->day->summary(),
            'unresolvedPreviousDay' => app(CashboxManager::class)->unresolvedPreviousDay(),
            'history' => CashboxDay::latest('date')->limit(14)->get()->map(fn (CashboxDay $day): array => ['day' => $day, 'summary' => $day->summary()]),
        ];
    }
}
