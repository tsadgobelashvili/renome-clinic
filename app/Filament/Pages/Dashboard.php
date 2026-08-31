<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\Tables\VisitsTable;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Support\ProductSaleForm;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\Visit;
use App\Services\FinanceManager;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'მთავარი';

    protected array $extraBodyAttributes = ['class' => 'renome-dashboard-body'];

    protected string $view = 'filament.pages.dashboard';

    public function getHeading(): string | Htmlable | null
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return VisitsTable::configure(
            $table,
            VisitResource::getUrl('create', ['return' => 'dashboard']),
        )
            ->query(fn (): Builder => VisitResource::getEloquentQuery())
            ->recordUrl(fn (Visit $record): string => VisitResource::getUrl('edit', [
                'record' => $record,
                'return' => 'dashboard',
            ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cashboxOverview')
                ->label('სალარო')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading('დღევანდელი სალარო')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('დახურვა')
                ->extraModalFooterActions(fn (): array => [
                    $this->openingBalanceAction(),
                    $this->expenseAction(),
                    $this->productSaleAction(),
                ])
                ->modalContent(fn () => view('filament.pages.dashboard-cashbox-modal', $this->cashboxModalData())),
            Action::make('cashboxPaymentDetails')
                ->label('გადახდის დეტალები')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading('გადახდის დეტალები')
                ->modalWidth('3xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('დახურვა')
                ->modalContent(function (array $arguments) {
                    $transaction = CashboxTransaction::query()
                        ->with([
                            'patient',
                            'visit.doctor',
                            'visit.treatmentCaseItems.treatmentCase',
                            'productSale.items.product',
                        ])
                        ->findOrFail($arguments['transaction']);

                    return view('filament.pages.dashboard-cashbox-payment-details', compact('transaction'));
                }),
            VisitForm::tomographyAction(standalone: true)
                ->label('ტომოგრაფია')
                ->modalSubmitActionLabel('შენახვა და გადახდა')
                ->extraAttributes(['class' => 'hidden']),
        ];
    }

    private function openingBalanceAction(): Action
    {
        return Action::make('dashboardOpeningBalance')
            ->label('საწყისი ნაშთი')
            ->icon('heroicon-o-wallet')
            ->color('gray')
            ->extraAttributes(['class' => 'hidden'])
            ->disabled(fn (): bool => app(CashboxManager::class)->today()->status === 'closed')
            ->schema([
                TextInput::make('opening_balance')->label('დასამატებელი GEL')->numeric()->minValue(0)->default(0)->suffix('₾'),
                TextInput::make('opening_balance_usd')->label('დასამატებელი USD')->numeric()->minValue(0)->default(0)->prefix('$'),
            ])
            ->action(function (array $data, CashboxManager $manager): void {
                $manager->addOpeningBalance(
                    $manager->today(),
                    (float) ($data['opening_balance'] ?? 0),
                    (float) ($data['opening_balance_usd'] ?? 0),
                );
                $this->refreshDashboardCashbox('საწყისი ნაშთი განახლდა.');
            });
    }

    private function expenseAction(): Action
    {
        return Action::make('dashboardExpense')
            ->label('ხარჯი')
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            ->extraAttributes(['class' => 'hidden'])
            ->disabled(fn (): bool => app(CashboxManager::class)->today()->status === 'closed'
                || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
            ->schema([
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->required()->suffix('₾'),
                Select::make('category')->label('კატეგორია')->options(FinanceTransaction::CATEGORIES)->required(),
                DateTimePicker::make('transaction_date')->label('თარიღი / დრო')->timezone(config('app.timezone'))->required()->default(now()),
                Textarea::make('description')->label('აღწერა / წყარო')->rows(2),
            ])
            ->action(function (array $data, FinanceManager $finance): void {
                $finance->create([
                    ...$data,
                    'type' => 'expense',
                    'currency' => 'GEL',
                    'payment_method' => 'cash',
                    'cash_source' => 'current_cashier',
                ]);
                $this->refreshDashboardCashbox('ხარჯი დაემატა.');
            });
    }

    private function productSaleAction(): Action
    {
        return Action::make('dashboardProductSale')
            ->label('პროდუქტის გაყიდვა')
            ->icon('heroicon-o-shopping-bag')
            ->color('gray')
            ->extraAttributes(['class' => 'hidden'])
            ->modalWidth('4xl')
            ->disabled(fn (): bool => app(CashboxManager::class)->today()->status === 'closed'
                || app(CashboxManager::class)->unresolvedPreviousDay() !== null)
            ->schema(ProductSaleForm::schema(includeDate: false, compact: true))
            ->action(function (array $data, ProductSaleService $sales): void {
                $sales->create($data);
                $this->refreshDashboardCashbox('პროდუქტის გაყიდვა დაფიქსირდა.');
            });
    }

    private function refreshDashboardCashbox(string $message): void
    {
        $this->resetTable();
        $this->dispatch('$refresh');
        Notification::make()->success()->title($message)->send();
    }

    /** @return array<string, mixed> */
    private function cashboxModalData(): array
    {
        $day = app(CashboxManager::class)->today();

        return [
            'day' => $day,
            'summary' => $day->summary(),
            'transactions' => CashboxTransaction::query()
                ->with([
                    'patient',
                    'visit.doctor',
                    'visit.treatmentCaseItems.treatmentCase',
                    'productSale.items.product',
                ])
                ->where('cashbox_day_id', $day->getKey())
                ->where('payment_method', 'cash')
                ->latest('transaction_date')
                ->get(),
            'historyUrl' => Cashbox::getUrl().'#history',
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $cashbox = app(CashboxManager::class)->today()->summary();
        $tomographyVisits = Visit::query()
            ->whereDate('visit_date', today()->toDateString())
            ->whereHas('treatmentCaseItems.treatmentCase', fn (Builder $query): Builder => $query
                ->where('category', 'tomography'));

        $tomographyCount = (clone $tomographyVisits)
            ->join('visit_treatment_cases', 'visit_treatment_cases.visit_id', '=', 'visits.id')
            ->join('treatment_cases', 'treatment_cases.id', '=', 'visit_treatment_cases.treatment_case_id')
            ->where('treatment_cases.category', 'tomography')
            ->sum('visit_treatment_cases.quantity');

        $tomographyPayments = Payment::query()
            ->whereDate('payment_date', today()->toDateString())
            ->whereHas('visit', fn (Builder $query): Builder => $query
                ->whereDate('visit_date', today()->toDateString())
                ->whereHas('treatmentCaseItems.treatmentCase', fn (Builder $query): Builder => $query
                    ->where('category', 'tomography')))
            ->selectRaw('currency, SUM(amount) AS amount')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->currency => (float) $row->amount]);

        return [
            'cashBalances' => $cashbox['expectedByCurrency'],
            'tomographyCount' => (int) $tomographyCount,
            'tomographyPayments' => $tomographyPayments,
        ];
    }
}
