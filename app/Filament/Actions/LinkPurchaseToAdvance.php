<?php

namespace App\Filament\Actions;

use App\Models\EmployeeAdvance;
use App\Models\Purchase;
use App\Services\EmployeeAdvanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class LinkPurchaseToAdvance
{
    public static function make(): Action
    {
        return Action::make('linkAdvance')->label('ავანსთან მიბმა')->icon('heroicon-o-link')->modalWidth('lg')
            ->visible(fn (Purchase $record) => auth()->user()?->canManageOwnerModules() && $record->source === 'rs')
            ->modalDescription('დადასტურდება RS დოკუმენტის ხარჯი. თანხა სალაროდან ან ბანკიდან მეორედ არ ჩამოიჭრება.')
            ->schema([
                Select::make('advance_id')->label('თანამშრომლის ავანსი')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => EmployeeAdvance::with('employee')->withTotals()
                        ->where('is_salary_advance', false)
                        ->whereDoesntHave('entries', fn ($q) => $q->where('kind', 'return'))
                        ->whereRaw('amount > COALESCE((SELECT SUM(amount) FROM employee_advance_entries WHERE employee_advance_id = employee_advances.id), 0)')
                        ->whereHas('employee', fn ($q) => $q->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%'.mb_strtolower($search).'%']))
                        ->latest('date')->limit(30)->get()->mapWithKeys(fn ($advance) => [$advance->id => self::label($advance)]))
                    ->getOptionLabelUsing(fn ($value) => ($advance = EmployeeAdvance::with('employee')->withTotals()->find($value)) ? self::label($advance) : null),
                DatePicker::make('expense_date')->label('ხარჯის დადასტურების თარიღი')->required()->default(today())->maxDate(today()),
            ])->modalSubmitActionLabel('დადასტურება')->action(function (Purchase $record, array $data): void {
                app(EmployeeAdvanceService::class)->settlePurchase((int) $data['advance_id'], $record->id, $data['expense_date'], (string) Str::uuid(), auth()->user());
                Notification::make()->success()->title('დოკუმენტი მიბმულია ავანსთან')->send();
            });
    }

    private static function label(EmployeeAdvance $advance): string
    {
        return '#'.$advance->id.' · '.$advance->employee->full_name.' · '.$advance->date->format('d.m.Y').' · '.$advance->remaining_amount.' GEL';
    }
}
