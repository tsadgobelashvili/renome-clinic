<?php

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Models\EmployeeAdvance;
use App\Services\EmployeeAdvanceService;
use App\Services\PurchaseExpenseAllocation;
use App\Support\ExpenseCategoryForm;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class ViewEmployeeAdvance extends ViewRecord
{
    use WithPagination;

    protected static string $resource = EmployeeAdvanceResource::class;

    protected string $view = 'filament.resources.employee-advances.view';

    #[Locked]
    public string $entryKey = '';

    #[Locked]
    public string $returnAmount = '0';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manualExpense')->label('ხარჯის დამატება')->icon('heroicon-o-plus')->modalWidth('lg')
                ->visible(fn () => in_array($this->record->status, ['open', 'partial', 'overspent']))
                ->fillForm(function (): array {
                    $this->entryKey = (string) Str::uuid();

                    return ['expense_date' => today()->toDateString()];
                })
                ->schema([Grid::make(2)->schema([
                    DatePicker::make('expense_date')->label('ხარჯის თარიღი')->required()->maxDate(today()),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->suffix('GEL')->required(),
                    ...ExpenseCategoryForm::schema(),
                    TextInput::make('description')->label('დანიშნულება')->maxLength(500)->required()->columnSpanFull(),
                    Textarea::make('note')->label('შენიშვნა')->rows(2)->maxLength(5000)->columnSpanFull(),
                ])])->action(function (array $data): void {
                    $this->authorizeAccess();
                    app(EmployeeAdvanceService::class)->manualExpense($this->record->id, [...$data, 'posting_key' => $this->entryKey], auth()->user());
                    $this->refreshAdvance();
                }),
            Action::make('returnRemaining')->label('ნაშთის დაბრუნება')->color('gray')->requiresConfirmation()
                ->visible(fn () => (float) $this->record->remaining_amount > 0)
                ->mountUsing(function (): void {
                    $this->authorizeAccess();
                    $advance = EmployeeAdvance::withTotals()->findOrFail($this->record->id);
                    $this->entryKey = (string) Str::uuid();
                    $this->returnAmount = $advance->remaining_amount;
                })
                ->modalDescription(fn () => 'დადასტურდეს '.number_format((float) $this->returnAmount, 2).' GEL-ის დაბრუნება? ნაღდი თანხა დაბრუნდება ავანსის თავდაპირველ წყაროში. ბანკის/სხვა წყაროს დაბრუნება მხოლოდ რეალური დაბრუნების შემდეგ დაადასტურეთ.')
                ->action(function (): void {
                    $this->authorizeAccess();
                    app(EmployeeAdvanceService::class)->returnRemaining($this->record->id, today()->toDateString(), $this->entryKey, $this->returnAmount, auth()->user());
                    $this->refreshAdvance();
                }),
        ];
    }

    private function refreshAdvance(): void
    {
        $this->record = EmployeeAdvance::with('employee')->withTotals()->findOrFail($this->record->id);
        Notification::make()->success()->title('შენახულია')->send();
    }

    protected function getViewData(): array
    {
        // Rehydrate aggregates together after Livewire restores the model, including concurrent confirmations.
        $this->record = EmployeeAdvance::with('employee')->withTotals()->findOrFail($this->record->id);

        return ['advance' => $this->record, 'entries' => $this->record->entries()->with(['purchase', 'direction', 'expenseType'])->latest('id')->paginate(25),
            'unallocated' => app(PurchaseExpenseAllocation::class)->advanceDistribution($this->record->id)->whereNull('expense_direction_id')->sum('amount'),
            'totals' => $this->record->entries()->selectRaw('kind, SUM(amount) AS total')->groupBy('kind')->pluck('total', 'kind')];
    }
}
