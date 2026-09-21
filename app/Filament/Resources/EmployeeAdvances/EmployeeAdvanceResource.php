<?php

namespace App\Filament\Resources\EmployeeAdvances;

use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeAdvanceResource extends Resource
{
    protected static ?string $model = EmployeeAdvance::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'თანამშრომლის ავანსები';

    protected static ?string $modelLabel = 'თანამშრომლის ავანსი';

    protected static ?string $pluralModelLabel = 'თანამშრომლის ავანსები';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('employee_id')->label('თანამშრომელი')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Employee::where('is_active', true)
                    ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%'.mb_strtolower($search).'%'])
                    ->orderBy('first_name')->limit(30)->get(['id', 'first_name', 'last_name'])->pluck('full_name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Employee::find($value)?->full_name),
            DatePicker::make('date')->label('გაცემის თარიღი')->default(today())->maxDate(today())->required(),
            Select::make('source')->label('წყარო')->options(EmployeeAdvance::SOURCES)->default('cashbox')->required()->live(),
            TextInput::make('amount')->label('გასაცემი თანხა')->numeric()->minValue(0.01)->step(0.01)->suffix('GEL')->required(),
            Select::make('bank_transaction_id')->label('საბანკო გასავალი')->visible(fn (Get $get) => $get('source') === 'bank')
                ->required(fn (Get $get) => $get('source') === 'bank')->searchable()->columnSpanFull()
                ->helperText('აირჩიეთ უკვე არსებული გასავალი. თარიღი და თანხა უნდა ემთხვეოდეს ავანსს.')
                ->getSearchResultsUsing(fn (string $search) => BankTransaction::where('direction', 'outflow')->where('currency', 'GEL')
                    ->whereDoesntHave('purchases')->whereNotIn('id', EmployeeAdvance::whereNotNull('bank_transaction_id')->select('bank_transaction_id'))
                    ->where(fn ($q) => $q->where('counterparty_name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'))
                    ->latest('transaction_date')->limit(30)->get()->mapWithKeys(fn ($bank) => [$bank->id => self::bankLabel($bank)]))
                ->getOptionLabelUsing(fn ($value) => ($bank = BankTransaction::find($value)) ? self::bankLabel($bank) : null),
            Textarea::make('note')->label('შენიშვნა')->rows(2)->maxLength(5000)->columnSpanFull(),
        ]);
    }

    private static function bankLabel(BankTransaction $bank): string
    {
        return $bank->transaction_date->format('d.m.Y').' · '.$bank->amount.' GEL · '.$bank->counterparty_name;
    }

    public static function table(Table $table): Table
    {
        return $table->striped()->columns([
            TextColumn::make('date')->label('თარიღი')->date('d.m.Y')->sortable(),
            TextColumn::make('employee.full_name')->label('თანამშრომელი')->searchable(['first_name', 'last_name']),
            TextColumn::make('source')->label('წყარო')->formatStateUsing(fn ($state) => EmployeeAdvance::SOURCES[$state]),
            TextColumn::make('amount')->label('გაცემული')->money('GEL')->alignEnd(),
            TextColumn::make('confirmed_expense_amount')->label('დადასტურებული ხარჯი')->money('GEL')->alignEnd(),
            TextColumn::make('remaining_amount')->label('დარჩენილი')->money('GEL')->alignEnd(),
            TextColumn::make('status')->label('სტატუსი')->badge()->formatStateUsing(fn ($state) => EmployeeAdvance::STATUSES[$state])
                ->color(fn ($state) => match ($state) {
                    'settled' => 'success', 'overspent' => 'warning', default => 'gray'
                }),
        ])->filters([SelectFilter::make('source')->label('წყარო')->options(EmployeeAdvance::SOURCES)])
            ->recordActions([ViewAction::make()->label('გახსნა')])->defaultSort('date', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('employee')->withTotals();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEmployeeAdvances::route('/'), 'create' => Pages\CreateEmployeeAdvance::route('/create'), 'view' => Pages\ViewEmployeeAdvance::route('/{record}')];
    }
}
