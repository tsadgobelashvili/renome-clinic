<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalaryRate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'ადმინისტრირება';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('employees.plural');
    }

    public static function getModelLabel(): string
    {
        return __('employees.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('employees.plural');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->label(__('employees.first_name'))->required()->maxLength(255),
            TextInput::make('last_name')->label(__('employees.last_name'))->required()->maxLength(255),
            DatePicker::make('birth_date')->label(__('employees.birth_date'))->displayFormat('d.m.Y'),
            TextInput::make('personal_id')->label(__('employees.personal_id'))->maxLength(255),
            TextInput::make('phone')->label(__('employees.phone'))->tel()->maxLength(255),
            Select::make('position_id')->label(__('employees.position'))->relationship(
                'position',
                'name',
                modifyQueryUsing: fn ($query) => $query->where('is_active', true),
            )->searchable()->preload()->native(false)->required()
                ->createOptionForm([
                    TextInput::make('name')->label(__('employees.position_name'))->required()->maxLength(255)->unique(EmployeePosition::class, 'name'),
                    Toggle::make('is_technician')->label(__('employees.is_technician'))->default(false),
                    Toggle::make('is_active')->label(__('employees.active'))->default(true),
                ])
                ->createOptionUsing(fn (array $data): int => EmployeePosition::create($data)->getKey()),
            Toggle::make('is_active')->label(__('employees.active'))->default(true),
            Select::make('user_id')->label(__('employees.linked_user'))->relationship('user', 'name')
                ->searchable()->preload()->native(false)->unique(ignoreRecord: true),
            Section::make(__('employees.salary.title'))->compact()->columns(3)->columnSpanFull()->schema([
                Select::make('salary_type')->label(__('employees.salary.type'))->options([
                    'fixed' => __('employees.salary.fixed'), 'performance' => __('employees.salary.performance'),
                ])->native(false)->live(),
                Toggle::make('salary_active')->label(__('employees.salary.active'))->default(false),
                Section::make(__('employees.salary.roles'))->compact()->columns(3)->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('salary_type') === 'performance')
                    ->schema(collect(Employee::salaryRoles())->map(fn (string $label, string $field) => Toggle::make($field)->label($label)->default(false)->live())->values()->all()),
                DatePicker::make('salary_effective_from')->label(__('employees.salary.effective_from'))->native(false)->displayFormat('d.m.Y'),
                TextInput::make('monthly_salary_gel')->label(__('employees.salary.monthly'))->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)->suffix('₾')
                    ->visible(fn (Get $get): bool => $get('salary_type') === 'fixed')->required(fn (Get $get): bool => $get('salary_type') === 'fixed'),
                Repeater::make('salaryRates')->label(__('employees.salary.rates'))->relationship()->defaultItems(0)->columns(4)->columnSpanFull()->compact()
                    ->visible(fn (Get $get): bool => $get('salary_type') === 'performance')->schema([
                        Select::make('work_type')->label(__('lab.work_type'))->options(function (Get $get): array {
                            $types = EmployeeSalaryRate::workTypes();
                            if ($get('../../salary_modeler') && ! $get('../../salary_main_technician')) {
                                foreach (['zircon', 'pmma', 'main_other', 'individual_abutment'] as $mainType) {
                                    if ($get('work_type') !== $mainType) {
                                        unset($types[$mainType]);
                                    }
                                }
                            }

                            return $types;
                        })->native(false)->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        TextInput::make('amount')->label(__('employees.salary.rate'))->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)->suffix('₾')->required(),
                        Select::make('basis')->label(__('employees.salary.basis'))->options(['per_unit' => __('employees.salary.per_unit'), 'per_work' => __('employees.salary.per_work')])->default('per_unit')->native(false)->required(),
                        Toggle::make('is_active')->label(__('employees.active'))->default(true),
                    ]),
            ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('full_name')->label(__('employees.name'))->searchable(['first_name', 'last_name'])->sortable(['first_name', 'last_name']),
            TextColumn::make('position.name')->label(__('employees.position'))->badge()->searchable()->sortable(),
            TextColumn::make('phone')->label(__('employees.phone'))->searchable()->placeholder('—'),
            IconColumn::make('is_active')->label(__('employees.active'))->boolean(),
            TextColumn::make('user.name')->label(__('employees.linked_user'))->placeholder('—'),
        ])->filters([
            SelectFilter::make('position_id')->label(__('employees.position'))->relationship('position', 'name')->searchable()->preload(),
            TernaryFilter::make('is_active')->label(__('employees.active')),
        ])->recordActions([ViewAction::make(), EditAction::make()])->recordUrl(fn (Employee $record): string => static::getUrl('view', ['record' => $record]))->defaultSort('first_name');
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployees::route('/'), 'create' => CreateEmployee::route('/create'), 'view' => ViewEmployee::route('/{record}'), 'edit' => EditEmployee::route('/{record}/edit')];
    }
}
