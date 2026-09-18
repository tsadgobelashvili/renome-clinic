<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Pages\Concerns\HasBogCurrentBalance;
use App\Models\BankCategory;
use App\Models\BankImportBatch;
use App\Models\BankTransaction;
use App\Models\ExpenseSubcategory;
use App\Models\Purchase;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankImportRollbackService;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\Bank\BankReport;
use App\Services\Bank\BankStatementImportService;
use App\Services\Bank\BogBankSyncService;
use App\Services\ExpenseDimensions;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithPagination;
use RuntimeException;

class Bank extends Page
{
    use AuthorizesPageAccess;

    public function updatedExpenseDirectionId(): void
    {
        $this->expenseTypeId = null;
    }

    use HasBogCurrentBalance;
    use WithPagination;

    protected string $view = 'filament.pages.bank';

    protected static ?int $navigationSort = 33;

    public string $dateFrom = '';

    public string $dateUntil = '';

    public string $period = '7d';

    public string $direction = '';

    public string $currency = '';

    public string $category = '';

    public string $operationType = '';

    public string $search = '';

    public string $viewMode = 'all';

    public bool $uncategorizedExpenses = false;

    public string $rsStatus = '';

    public string $rsSearch = '';

    public array $rsDocuments = [];

    #[Locked]
    public ?int $rsTransactionId = null;

    #[Locked]
    public ?string $lastBogSyncAt = null;

    public ?int $expenseCategoryId = null;

    public ?int $expenseSubcategoryId = null;

    public ?int $expenseDirectionId = null;

    public ?int $expenseTypeId = null;

    public string $expenseDirection = '';

    public string $expenseType = '';

    public bool $rememberRule = false;

    public bool $updateSavedRule = false;

    public bool $applyExisting = false;

    public bool $confirmCompanyDefault = false;

    public bool $useCounterpartyAccount = false;

    public string $ruleKeyword = '';

    public bool $showHistory = false;

    #[Locked]
    public ?int $transactionId = null;

    #[Locked]
    public ?int $batchId = null;

    public static function getNavigationGroup(): ?string
    {
        return 'ფინანსები';
    }

    public static function getNavigationLabel(): string
    {
        return __('bank.title');
    }

    public function getTitle(): string
    {
        return __('bank.title');
    }

    public function mount(): void
    {
        $this->applyPeriod('7d');
        $this->lastBogSyncAt = app(BogBankSyncService::class)->lastSuccessfulSync()?->format('d.m.Y H:i');
    }

    public function applyPeriod(string $period): void
    {
        abort_unless(static::canAccess(), 403);
        if (! in_array($period, ['7d', '1m', '3m', '1y', 'custom'], true)) {
            return;
        }
        $this->period = $period;
        if ($period !== 'custom') {
            $this->dateUntil = today()->toDateString();
            $this->dateFrom = match ($period) {
                '7d' => today()->subDays(6)->toDateString(),
                '1m' => today()->subMonthNoOverflow()->addDay()->toDateString(),
                '3m' => today()->subMonthsNoOverflow(3)->addDay()->toDateString(),
                '1y' => today()->subYearNoOverflow()->addDay()->toDateString(),
            };
        }
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property === 'expenseCategoryId') {
            $this->expenseSubcategoryId = null;
        }
        if ($property === 'period') {
            $this->applyPeriod($this->period);
        }
        if (in_array($property, ['dateFrom', 'dateUntil'], true)) {
            $this->period = 'custom';
        }
        if (in_array($property, ['dateFrom', 'dateUntil', 'direction', 'currency', 'category', 'operationType', 'search', 'viewMode', 'uncategorizedExpenses', 'expenseDirection', 'expenseType', 'rsStatus'], true)) {
            $this->resetPage();
            $this->transactionId = null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncBog')->label(__('bog-transactions.sync'))->size('sm')
                ->action(function (BogBankSyncService $sync): void {
                    abort_unless(static::canAccess(), 403);
                    try {
                        $result = $sync->sync();
                    } catch (QueryException) {
                        Notification::make()->danger()->title(__('bog-transactions.sync'))->body(__('bog-transactions.database_error'))->send();

                        return;
                    } catch (RuntimeException|DomainException|ValidationException $exception) {
                        Notification::make()->danger()->title(__('bog-transactions.sync'))->body($exception->getMessage())->send();

                        return;
                    }
                    $this->resetPage();
                    $this->lastBogSyncAt = $sync->lastSuccessfulSync()?->format('d.m.Y H:i');
                    $this->refreshBogBalance();
                    Notification::make()->status($result['errors'] ? 'warning' : 'success')->title(__('bog-transactions.sync'))
                        ->body(__('bog-transactions.sync_summary', [...$result, 'errors' => count($result['errors'])]))->send();
                }),
            Action::make('lastBogSync')->view('filament.pages.bank-last-sync'),
            Action::make('importStatement')->label(__('bank.import'))->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('file')->label(__('bank.statement_file'))->disk('local')->directory('bank-statements')->visibility('private')
                        ->storeFileNamesIn('original_name')->storeFiles(true)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/plain',
                            'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
                        ])
                        ->mimeTypeMap(['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv'])
                        ->rules(['extensions:xlsx,csv'])
                        // MIME sniffers sometimes label XLSX as ZIP and CSV as plain text.
                        // Keep a random name but preserve the validated format for the parser.
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::uuid().'.'.strtolower($file->getClientOriginalExtension()))
                        ->maxSize(10240)->required()->helperText(__('bank.upload_help')),
                ])->action(function (array $data, BankStatementImportService $importer): void {
                    abort_unless(static::canAccess(), 403);
                    $path = $data['file'];
                    // Never accept a forged path to a file elsewhere on the private disk.
                    if (! preg_match('~^bank-statements/[a-zA-Z0-9_-]+\.(xlsx|csv)$~', $path)) {
                        throw ValidationException::withMessages(['file' => __('bank.xlsx_only')]);
                    }
                    try {
                        $batch = $importer->import(Storage::disk('local')->path($path), $data['original_name'] ?? basename($path), auth()->id(), $path);
                    } catch (DomainException $exception) {
                        if (! BankImportBatch::where('stored_path', $path)->exists()) {
                            Storage::disk('local')->delete($path);
                        }
                        Notification::make()->danger()->title(__('bank.import_failed'))->body($exception->getMessage())->persistent()->send();

                        return;
                    }
                    $this->batchId = $batch->id;
                    $this->showHistory = true;
                    Notification::make()->status($batch->rejected_rows ? 'warning' : 'success')->title(__('bank.import_complete'))
                        ->body(__('bank.import_summary', ['imported' => $batch->imported_rows, 'duplicates' => $batch->duplicate_rows, 'rejected' => $batch->rejected_rows]))->persistent()->send();
                }),
            Action::make('history')->label(__('bank.history'))->color('gray')->action(fn () => $this->showHistory = ! $this->showHistory),
            Action::make('categories')->label(__('expense-categories.title'))->color('gray')->url(ExpenseCategories::getUrl()),
            Action::make('rules')->label(__('bank-accounting.rules'))->color('gray')->url(BankRules::getUrl()),
        ];
    }

    public function showTransaction(?int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->transactionId = $id;
        $record = $id ? BankTransaction::with('categorizationRule')->findOrFail($id) : null;
        $this->expenseCategoryId = $record?->expense_category_id;
        $this->expenseSubcategoryId = $record?->expense_subcategory_id;
        $this->expenseDirectionId = $record?->expense_direction_id;
        $this->expenseTypeId = $record?->expense_type_id;
        $this->ruleKeyword = $record?->categorizationRule?->purpose_keyword ?? '';
        $this->rememberRule = $this->updateSavedRule = $this->applyExisting = $this->confirmCompanyDefault = $this->useCounterpartyAccount = false;
        $this->resetValidation();
    }

    public function toggleTransaction(int $id): void
    {
        $this->showTransaction($this->transactionId === $id ? null : $id);
    }

    public function rsMatchingAction(): Action
    {
        return Action::make('rsMatching')->label('RS')->size('xs')->color('gray')->modalWidth('3xl')
            ->modalHeading(__('bank-rs.title'))->modalSubmitActionLabel(__('bank-rs.confirm'))
            ->mountUsing(function (array $arguments): void {
                abort_unless(static::canAccess(), 403);
                $record = BankTransaction::findOrFail($arguments['transaction']);
                $this->rsTransactionId = $record->id;
                $this->rsSearch = '';
                $this->rsDocuments = DB::table('bank_purchase_matches')->where('bank_transaction_id', $record->id)->pluck('amount', 'purchase_id')->all();
            })
            ->modalContent(fn () => view('filament.pages.bank-rs-matching', $this->rsMatchingData()))
            ->action(function (BankPurchaseMatching $matching): void {
                abort_unless(static::canAccess() && $this->rsTransactionId, 403);
                $documents = collect($this->rsDocuments)->map(fn ($amount, $id) => ['purchase_id' => $id, 'amount' => $amount])->values()->all();
                $matching->confirm($this->rsTransactionId, $documents, auth()->user());
                Notification::make()->success()->title(__('bank-rs.saved'))->send();
            });
    }

    public function toggleRsDocument(int $id): void
    {
        abort_unless(static::canAccess() && $this->rsTransactionId, 403);
        if (array_key_exists($id, $this->rsDocuments)) {
            unset($this->rsDocuments[$id]);

            return;
        }
        $purchase = Purchase::where('source', 'rs')->findOrFail($id);
        $total = (float) $purchase->items()->sum('line_total');
        $used = (float) DB::table('bank_purchase_matches')->where('purchase_id', $id)->where('bank_transaction_id', '!=', $this->rsTransactionId)->sum('amount');
        $this->rsDocuments[$id] = number_format(max(0, $total - $used), 2, '.', '');
    }

    private function rsMatchingData(): array
    {
        abort_unless(static::canAccess() && $this->rsTransactionId, 403);
        $matching = app(BankPurchaseMatching::class);
        $record = BankTransaction::findOrFail($this->rsTransactionId);
        $selected = Purchase::with('supplier')->whereIn('id', array_keys($this->rsDocuments))->get();

        return [
            'record' => $record, 'selected' => $selected, 'rsDocuments' => $this->rsDocuments, 'rsErrors' => $this->getErrorBag()->all(),
            'suggestions' => $matching->suggestions($record, mb_substr($this->rsSearch, 0, 255)),
            'rsSummary' => $matching->summary($record->id),
            'rsAllocation' => $matching->distribution()->where('bank_transaction_id', $record->id)->get(),
        ];
    }

    public function saveInlineClassification(BankExpenseAssignment $assignment): void
    {
        abort_unless(static::canAccess() && $this->transactionId, 403);
        app(ExpenseDimensions::class)->validate(['expense_direction_id' => $this->expenseDirectionId, 'expense_type_id' => $this->expenseTypeId], BankTransaction::findOrFail($this->transactionId), required: true);
        // The explicit Remember checkbox confirms a company-wide rule in the compact editor.
        $this->confirmCompanyDefault = $this->rememberRule;
        if ($this->rememberRule) {
            $record = BankTransaction::findOrFail($this->transactionId);
            $this->useCounterpartyAccount = blank($record->counterparty_name) && filled($record->counterparty_account);
        }
        $this->saveExpenseClassification($assignment);
    }

    public function saveExpenseClassification(BankExpenseAssignment $assignment): void
    {
        abort_unless(static::canAccess() && $this->transactionId, 403);
        $assignment->assign($this->transactionId, [
            ...($this->expenseDirectionId || $this->expenseTypeId ? ['expense_direction_id' => $this->expenseDirectionId, 'expense_type_id' => $this->expenseTypeId] : []),
            'expense_category_id' => $this->expenseCategoryId, 'expense_subcategory_id' => $this->expenseSubcategoryId,
            'remember' => $this->rememberRule, 'update_rule' => $this->updateSavedRule, 'purpose_keyword' => $this->ruleKeyword,
            'confirm_company_default' => $this->confirmCompanyDefault, 'use_account' => $this->useCounterpartyAccount, 'apply_existing' => $this->applyExisting,
        ], auth()->user());
        $this->rememberRule = $this->updateSavedRule = $this->applyExisting = false;
        Notification::make()->success()->title(__('bank-rules.saved'))->send();
    }

    public function showBatch(?int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->batchId = $id;
    }

    public function rollbackImportAction(): Action
    {
        return Action::make('rollbackImport')->label(__('bank.rollback_import'))->color('danger')
            ->visible(fn (): bool => static::canAccess())
            ->requiresConfirmation()->modalHeading(__('bank.rollback_import'))
            ->modalDescription(function (array $arguments): string {
                abort_unless(static::canAccess(), 403);
                $batch = BankImportBatch::withCount('transactions')->findOrFail($arguments['batch']);

                return __('bank.rollback_confirm', [
                    'file' => $batch->source_file, 'from' => $batch->period_from?->format('d.m.Y') ?? '—',
                    'to' => $batch->period_to?->format('d.m.Y') ?? '—', 'count' => $batch->transactions_count,
                ]);
            })
            ->action(function (array $arguments, BankImportRollbackService $rollback): void {
                abort_unless(static::canAccess(), 403);
                $count = $rollback->rollback((int) $arguments['batch'], auth()->user());
                $this->transactionId = null;
                Notification::make()->success()->title(__('bank.rollback_complete', ['count' => $count]))->send();
            });
    }

    public function assignCategory(int $id, ?string $categoryId): void
    {
        abort_unless(static::canAccess(), 403);
        $record = BankTransaction::findOrFail($id);
        if (filled($categoryId) && (string) $record->bank_category_id !== $categoryId && ! BankCategory::whereKey($categoryId)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['category' => __('bank.inactive_category')]);
        }
        $category = filled($categoryId) ? BankCategory::findOrFail($categoryId) : null;
        $record->update(['bank_category_id' => $category?->id, 'expense_category_id' => $category?->accounting_treatment === 'expense' ? $category->expense_category_id : null,
            'expense_subcategory_id' => null, 'classification_source' => 'manual', 'include_embedded_fee' => false]);
        if ($this->transactionId === $id) {
            $this->expenseCategoryId = $record->expense_category_id;
            $this->expenseSubcategoryId = null;
        }
    }

    public function markAlreadyRecorded(int $id, bool $excluded): void
    {
        abort_unless(static::canAccess(), 403);
        BankTransaction::findOrFail($id)->update(['exclude_from_pnl' => $excluded]);
    }

    public function markLegacy(int $id, bool $legacy): void
    {
        abort_unless(static::canAccess(), 403);
        BankTransaction::findOrFail($id)->update(['is_legacy' => $legacy]);
    }

    public function includeEmbeddedFee(int $id, bool $include): void
    {
        abort_unless(static::canAccess(), 403);
        $record = BankTransaction::with('category')->findOrFail($id);
        if ($include && ($record->direction !== 'inflow' || $record->category?->accounting_treatment !== 'settlement' || (float) $record->bank_fee <= 0)) {
            throw ValidationException::withMessages(['embedded_fee' => __('bank-accounting.embedded_fee_help')]);
        }
        $record->update(['include_embedded_fee' => $include]);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($this->viewMode, ['relevant', 'all'], true), 422);
        $filters = collect(['dateFrom', 'dateUntil', 'direction', 'currency', 'category', 'operationType', 'search', 'viewMode', 'uncategorizedExpenses', 'expenseDirection', 'expenseType', 'rsStatus'])->mapWithKeys(fn ($key) => [$key => $this->$key])->all();
        $filters['expenseCategory'] = $filters['category'];
        unset($filters['category']);
        $validator = validator($filters, ['dateFrom' => 'nullable|date_format:Y-m-d', 'dateUntil' => 'nullable|date_format:Y-m-d|after_or_equal:dateFrom', 'search' => 'nullable|string|max:255']);
        // Invalid/custom incomplete input never reaches date comparisons on the database.
        $dateError = $validator->fails() ? $validator->errors()->first() : null;
        if ($dateError) {
            $filters['dateFrom'] = '9999-12-31';
            $filters['dateUntil'] = '0001-01-01';
        }
        $report = app(BankReport::class);
        $options = BankTransaction::select('currency')->distinct()->get();

        return [
            'directionOptions' => app(ExpenseDimensions::class)->options('direction', $this->expenseDirectionId),
            'typeOptions' => app(ExpenseDimensions::class)->childOptions($this->expenseDirectionId, $this->expenseTypeId),
            'transactions' => $report->query([...$filters, 'includeRs' => true])->select(['id', 'transaction_date', 'direction', 'amount', 'currency', 'counterparty_name', 'description', 'expense_direction_id', 'expense_type_id', 'bank_fee', 'bank_category_id', 'expense_category_id', 'expense_subcategory_id', 'source', 'exclude_from_pnl', 'include_embedded_fee', 'is_legacy'])->addSelect('rs_summary.status as rs_status')->latest('transaction_date')->latest('id')->paginate(25),
            'totals' => $report->totals($filters),
            'categories' => BankCategory::orderBy('sort_order')->orderBy('name')->get(),
            'expenseCategories' => app(ExpenseDimensions::class)->registry()->sortBy('sort_order'),
            'expenseSubcategories' => ExpenseSubcategory::orderBy('sort_order')->orderBy('name')->get(),
            'currencies' => $options->pluck('currency')->push('GEL')->push(config('services.bog.account_currency', 'GEL'))->filter()->unique()->sort()->values(),
            'history' => $this->showHistory ? BankImportBatch::select(['id', 'imported_at', 'source_file', 'period_from', 'period_to', 'accounts', 'currencies', 'opening_balance', 'closing_balance', 'imported_rows', 'duplicate_rows', 'rejected_rows', 'rolled_back_at'])->latest('id')->paginate(10, pageName: 'historyPage') : null,
            'transactionDetail' => $this->transactionId ? BankTransaction::findOrFail($this->transactionId) : null,
            'batchDetail' => $this->batchId ? BankImportBatch::findOrFail($this->batchId) : null,
            'dateError' => $dateError,
        ];
    }
}
