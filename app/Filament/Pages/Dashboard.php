<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\Tables\VisitsTable;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Support\ProductSaleForm;
use App\Models\CashboxTransaction;
use App\Models\Payment;
use App\Models\TreatmentEstimate;
use App\Models\Visit;
use App\Services\FinanceManager;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
use App\Support\ExpenseCategoryForm;
use App\Support\PaymentPresentation;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public ?int $pendingConsultationPlanId = null;

    protected static ?string $navigationLabel = 'მთავარი';

    protected array $extraBodyAttributes = ['class' => 'renome-dashboard-body'];

    protected string $view = 'filament.pages.dashboard';

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return VisitsTable::configure(
            $table,
            VisitResource::getUrl('create', ['return' => 'dashboard']),
            todayByDefault: true,
        )
            ->query(fn (): Builder => Visit::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'patient.latestTreatmentEstimate',
                'doctor',
                'treatmentCaseItems.treatmentCase',
                'payments.splits',
            ]))
            ->recordUrl(null)
            ->recordAction('visitDetails')
            ->recordActions([
                Action::make('treatmentPlan')
                    ->label('გეგმა')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->size('xs')
                    ->color('gray')
                    ->visible(fn (Visit $record): bool => $record->patient?->latestTreatmentEstimate !== null)
                    ->url(fn (Visit $record): string => TreatmentEstimateResource::getUrl('view', [
                        'record' => $record->patient->latestTreatmentEstimate,
                    ])),
                Action::make('visitDetails')
                    ->label('ვიზიტის დეტალები')
                    ->extraAttributes(['class' => 'hidden'])
                    ->modalHeading('ვიზიტის დეტალები')
                    ->modalWidth('4xl')
                    ->modalSubmitAction(fn (Action $action, Visit $record): Action => $action
                        ->label('რედაქტირება')
                        ->icon('heroicon-o-pencil-square')
                        ->color('gray')
                        ->url(VisitResource::getUrl('edit', [
                            'record' => $record,
                            'return' => 'dashboard',
                        ])))
                    ->modalCancelActionLabel('დახურვა')
                    ->modalContent(fn (Visit $record) => view('filament.pages.dashboard-visit-details', [
                        'visit' => $record->loadMissing([
                            'patient',
                            'doctor',
                            'treatmentCaseItems.treatmentCase',
                            'payments.splits',
                        ]),
                    ])),
            ], RecordActionsPosition::AfterContent);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newVisit')
                ->label('ახალი ვიზიტი')
                ->extraAttributes(['class' => 'hidden'])
                ->model(Visit::class)
                ->modalHeading('ახალი ვიზიტი')
                ->modalWidth('3xl')
                ->extraModalWindowAttributes(['class' => 'renome-dashboard-new-visit-modal'])
                ->modalSubmitActionLabel('შენახვა')
                ->modalCancelActionLabel('გაუქმება')
                ->schema(VisitForm::dashboardCreateSchema())
                ->databaseTransaction()
                ->action(function (array $data): void {
                    $data['treatment_estimate_id'] = $this->pendingConsultationPlanId;
                    VisitForm::createDashboardVisit($data);
                    $this->pendingConsultationPlanId = null;
                    $this->resetTable();
                    $this->dispatch('$refresh');
                    Notification::make()->success()->title('ვიზიტი შენახულია.')->send();
                }),
            Action::make('cashboxOverview')
                ->label('სალარო')
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading(fn (): string => 'სალარო '.app(CashboxManager::class)->today()->date->format('d.m.Y'))
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('დახურვა')
                ->extraModalFooterActions(fn (): array => [
                    $this->openingBalanceAction(),
                    $this->expenseAction(),
                    $this->productSaleAction(),
                    $this->closeCashboxDayAction(),
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

    public function editDashboardTreatmentPlan(int $estimateId): void
    {
        $parentAction = collect($this->getMountedActions())
            ->first(fn ($action): bool => $action instanceof Action && $action->getName() === 'newVisit');
        $patientId = (int) ($parentAction?->getRawData()['patient_id'] ?? 0);
        $estimate = TreatmentEstimate::query()
            ->with(['doctor', 'options.items', 'options.stages.items'])
            ->where('patient_id', $patientId)
            ->findOrFail($estimateId);

        $this->mountAction('createEstimate');
        $action = $this->getMountedAction();
        $schemaName = $this->getMountedActionSchemaName();
        $schema = filled($schemaName) ? $this->getSchema($schemaName) : null;

        if (! $action instanceof Action || ! $schema instanceof Schema) {
            return;
        }

        $action->record($estimate);
        $schema->model($estimate);
        self::bindDashboardPlanSchema($schema, $estimate);
        $schema->fill([
            ...$estimate->attributesToArray(),
            'mode' => 'edit',
            'selected_estimate_id' => $estimate->getKey(),
        ]);
        $schema->loadStateFromRelationships(shouldHydrate: true);
    }

    private static function bindDashboardPlanSchema(Schema $schema, TreatmentEstimate $estimate): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $component->model($estimate);

            if ($component instanceof Repeater) {
                $component->clearCachedExistingRecords();
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                self::bindDashboardPlanSchema($childSchema, $estimate);
            }
        }
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
            ->disabled(fn (): bool => app(CashboxManager::class)->today()->status === 'closed')
            ->schema([
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->required()->suffix('₾'),
                ...ExpenseCategoryForm::schema(),
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
            ->disabled(fn (): bool => app(CashboxManager::class)->today()->status === 'closed')
            ->schema(ProductSaleForm::schema(includeDate: false, compact: true))
            ->action(function (array $data, ProductSaleService $sales): void {
                $sales->create($data);
                $this->refreshDashboardCashbox('პროდუქტის გაყიდვა დაფიქსირდა.');
            });
    }

    private function closeCashboxDayAction(): Action
    {
        return Action::make('dashboardCloseCashboxDay')
            ->label(fn (): string => 'დღის დახურვა '.app(CashboxManager::class)->oldestUnclosedDay()->date->format('d.m.Y'))
            ->color('success')
            ->modalHeading(fn (): string => 'დღის დახურვა '.app(CashboxManager::class)->oldestUnclosedDay()->date->format('d.m.Y'))
            ->modalDescription(fn (): string => 'დაადასტურეთ '.app(CashboxManager::class)->oldestUnclosedDay()->date->format('d.m.Y').' დღის სალაროს დახურვა.')
            ->schema([
                TextInput::make('actual_closing_balance')->label('ფაქტობრივი ნაღდი ნაშთი')->numeric()->minValue(0)->required()->suffix('₾')
                    ->default(fn (): float => app(CashboxManager::class)->oldestUnclosedDay()->summary()['expected']),
                TextInput::make('actual_closing_balance_usd')->label('ფაქტობრივი USD ნაშთი')->numeric()->minValue(0)->required()->prefix('$')
                    ->default(fn (): float => app(CashboxManager::class)->oldestUnclosedDay()->summary()['expectedByCurrency']['USD']),
                TextInput::make('carry_forward_balance')->label('მომდევნო დღისთვის დასატოვებელი')->numeric()->minValue(0)->required()->suffix('₾')->default(0),
                TextInput::make('carry_forward_balance_usd')->label('მომდევნო დღისთვის USD')->numeric()->minValue(0)->required()->prefix('$')->default(0),
                Textarea::make('notes')->label('შენიშვნა')->rows(2),
            ])
            ->action(function (array $data, CashboxManager $manager): void {
                $manager->close(
                    $manager->oldestUnclosedDay(),
                    (float) $data['actual_closing_balance'],
                    (float) $data['carry_forward_balance'],
                    $data['notes'] ?? null,
                    (float) $data['actual_closing_balance_usd'],
                    (float) $data['carry_forward_balance_usd'],
                );
                $this->refreshDashboardCashbox('სალაროს დღე დაიხურა.');
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
                ->whereIn('payment_method', ['cash', 'card'])
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
            ->distinct()
            ->count('patient_id');

        $tomographyPayments = PaymentPresentation::amountsByCurrency(Payment::query()
            ->with('splits')
            ->whereDate('payment_date', today()->toDateString())
            ->whereHas('visit', fn (Builder $query): Builder => $query
                ->whereDate('visit_date', today()->toDateString())
                ->whereHas('treatmentCaseItems.treatmentCase', fn (Builder $query): Builder => $query
                    ->where('category', 'tomography')))
            ->get());

        return [
            'cashBalances' => $cashbox['expectedByCurrency'],
            'cardReceipts' => $cashbox['cardIncomeByCurrency'],
            'tomographyCount' => (int) $tomographyCount,
            'tomographyPayments' => $tomographyPayments,
        ];
    }
}
