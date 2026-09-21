<?php

namespace App\Filament\Resources\Employees;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Concerns\HasReadablePersonUrls;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalaryRate;
use App\Models\TreatmentCase;
use App\Services\ClinicEmployeePayrollAmounts;
use App\Support\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\Builder;

class EmployeeResource extends Resource
{
    use HasReadablePersonUrls;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'პერსონალი';

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
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'position',
            fn (Builder $position): Builder => $position->where('is_technician', false),
        );
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
            )->searchable()->preload()->native(false)->required()->live()
                ->createOptionForm([
                    TextInput::make('name')->label(__('employees.position_name'))->required()->maxLength(255)->unique(EmployeePosition::class, 'name'),
                    Toggle::make('is_technician')->label(__('employees.is_technician'))->default(false),
                    Toggle::make('is_active')->label(__('employees.active'))->default(true),
                ])
                ->createOptionUsing(fn (array $data): int => EmployeePosition::create($data)->getKey()),
            Toggle::make('is_active')->label(__('employees.active'))->default(true),
            Toggle::make('show_in_lab_doctor_list')->label(__('employees.show_in_lab_doctor_list'))->default(false)
                ->visible(fn (Get $get): bool => filled($get('position_id')) && EmployeePosition::query()->assistant()->whereKey($get('position_id'))->exists()),
            Select::make('user_id')->label(__('employees.linked_user'))->relationship('user', 'name')
                ->searchable()->preload()->native(false)->unique(ignoreRecord: true),
            Section::make(__('employees.salary.title'))->compact()->columns(3)->columnSpanFull()
                ->visible(fn (Get $get): bool => self::positionIsTechnician($get('position_id')))->schema([
                    Select::make('salary_type')->label(__('employees.salary.type'))->options([
                        'fixed' => __('employees.salary.fixed'), 'performance' => __('employees.salary.performance'),
                    ])->native(false)->live(),
                    Toggle::make('salary_active')->label(__('employees.salary.active'))->default(false),
                    Section::make(__('employees.salary.roles'))->compact()->columns(3)->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('salary_type') === 'performance')
                        ->schema(collect(Employee::salaryRoles())->map(fn (string $label, string $field) => Toggle::make($field)->label($label)->default(false)->live())->values()->all()),
                    DatePicker::make('salary_effective_from')->label(__('employees.salary.effective_from'))->native(false)->displayFormat('d.m.Y'),
                    TextInput::make('salary_payment_schedule')
                        ->label(__('employees.salary.payment_schedule'))
                        ->placeholder(__('employees.salary.payment_schedule_placeholder'))
                        ->maxLength(100),
                    TextInput::make('monthly_salary_gel')->label(__('employees.salary.monthly'))->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)->suffix('₾')
                        ->visible(fn (Get $get): bool => $get('salary_type') === 'fixed')->required(fn (Get $get): bool => $get('salary_type') === 'fixed'),
                    Repeater::make('salaryRates')->label(__('employees.salary.rates'))
                        ->helperText(__('employees.salary.rate_history_help'))
                        ->relationship(modifyQueryUsing: fn ($query) => $query->orderBy('work_type')->orderByDesc('effective_from'))
                        ->defaultItems(0)->columns(['default' => 1, 'md' => 5])->columnSpanFull()->compact()
                        ->reorderable(false)->addActionLabel(__('employees.salary.add_rate'))
                        ->table([
                            Repeater\TableColumn::make(__('lab.work_type')),
                            Repeater\TableColumn::make(__('employees.salary.rate')),
                            Repeater\TableColumn::make(__('employees.salary.basis')),
                            Repeater\TableColumn::make(__('employees.salary.effective_from')),
                            Repeater\TableColumn::make(__('employees.active')),
                        ])
                        ->deleteAction(fn (Action $action) => $action
                            ->requiresConfirmation(fn (array $arguments, Repeater $component): bool => $component->getCachedExistingRecords()->has($arguments['item'] ?? ''))
                            ->modal(fn (Action $action): bool => $action->isConfirmationRequired())
                            ->modalHeading(__('employees.salary.delete_rate'))
                            ->modalDescription(__('employees.salary.delete_rate_confirmation'))
                            ->modalSubmitActionLabel(__('employees.salary.delete_rate'))
                            ->before(function (array $arguments, Repeater $component, Action $action): void {
                                $rate = $component->getCachedExistingRecords()->get($arguments['item'] ?? '');
                                if ($rate?->fresh()?->deletionProtected()) {
                                    Notification::make()->warning()->title(__('employees.salary.rate_delete_blocked'))->send();
                                    $action->halt();
                                }
                            }))
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
                            })->native(false)->required()->disabled(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ?? false),
                            TextInput::make('amount')->label(__('employees.salary.rate'))->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)->suffix('₾')->required()
                                ->disabled(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ?? false),
                            Select::make('basis')->label(__('employees.salary.basis'))->options(['per_unit' => __('employees.salary.per_unit'), 'per_work' => __('employees.salary.per_work')])->default('per_unit')->native(false)->required()
                                ->disabled(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ?? false),
                            DatePicker::make('effective_from')->label(__('employees.salary.effective_from'))->default(today())->required()->native(false)->displayFormat('d.m.Y')
                                ->minDate(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ? null : today())
                                ->disabled(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ?? false)
                                ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    $matching = collect($get('../../salaryRates') ?? [])->filter(fn ($row) => ($row['work_type'] ?? null) === $get('work_type') && substr((string) ($row['effective_from'] ?? ''), 0, 10) === substr((string) $value, 0, 10));
                                    if ($matching->count() > 1) {
                                        $fail(__('employees.salary.rate_date_duplicate'));
                                    }
                                }]),
                            Toggle::make('is_active')->label(__('employees.active'))->default(true)
                                ->disabled(fn (?EmployeeSalaryRate $record) => $record?->historyLocked() ?? false),
                        ]),
                ]),
            Section::make(__('employees.payroll.title'))->description(__('employees.payroll.description'))
                ->compact()->columnSpanFull()
                ->visible(fn (Get $get): bool => ! self::positionIsTechnician($get('position_id')))
                ->schema([
                    TextInput::make('salary_payout_day')->label(__('employees.payroll.payout_day'))
                        ->numeric()->integer()->minValue(1)->maxValue(31)->placeholder(__('employees.payroll.payout_day_placeholder')),
                    Repeater::make('payrollSettings')->relationship()->hiddenLabel()->defaultItems(0)->maxItems(2)
                        ->addActionLabel(__('employees.payroll.add_source'))->columns(4)->columnSpanFull()->compact()->schema([
                            Select::make('source')->label(__('employees.payroll.source'))->options([
                                'clinic' => __('employees.payroll.clinic'),
                                'israeli' => __('employees.payroll.israeli'),
                            ])->native(false)->required()->live()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Select::make('salary_model')->label(__('employees.payroll.salary_model'))->options([
                                'fixed_net' => __('employees.payroll.fixed_net'),
                                'fixed_gross' => __('employees.payroll.fixed_gross'),
                                'percentage' => __('employees.payroll.percentage'),
                                'per_unit' => __('employees.payroll.per_unit'),
                            ])->native(false)->required()->live(),
                            Select::make('currency')->label(__('employees.payroll.currency'))->options(
                                collect(Currency::OPTIONS)->mapWithKeys(fn (string $symbol, string $currency): array => [$currency => $currency.' ('.$symbol.')'])->all()
                            )->default(Currency::DEFAULT)->native(false)->required()->live(),
                            Select::make('default_payment_method')->label(__('employees.payroll.payment_method'))->options([
                                PaymentMethod::BankTransfer->value => __('employees.payroll.bank'),
                                PaymentMethod::Cash->value => __('employees.payroll.cash'),
                            ])->default(PaymentMethod::BankTransfer->value)->native(false)->required()->live(),
                            TextInput::make('net_amount')->label(__('employees.payroll.net_amount'))->numeric()->minValue(0)->step(0.01)->live(debounce: 150)
                                ->visible(fn (Get $get): bool => $get('salary_model') === 'fixed_net')
                                ->required(fn (Get $get): bool => $get('salary_model') === 'fixed_net'),
                            TextEntry::make('required_amount_preview')->label(__('employees.payroll.funding_required'))
                                ->state(fn (Get $get): string => Currency::format(ClinicEmployeePayrollAmounts::fromNet($get('net_amount'), $get('default_payment_method'))['required_amount'], $get('currency') ?: 'GEL'))
                                ->visible(fn (Get $get): bool => $get('source') === 'clinic' && $get('salary_model') === 'fixed_net'),
                            TextInput::make('gross_amount')->label(__('employees.payroll.gross_amount'))->numeric()->minValue(0)->step(0.01)
                                ->visible(fn (Get $get): bool => $get('salary_model') === 'fixed_gross')
                                ->required(fn (Get $get): bool => $get('salary_model') === 'fixed_gross'),
                            TextInput::make('percentage_rate')->label(__('employees.payroll.percentage_rate'))->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                                ->visible(fn (Get $get): bool => $get('salary_model') === 'percentage')
                                ->required(fn (Get $get): bool => $get('salary_model') === 'percentage'),
                            TextInput::make('per_unit_amount')->label(__('employees.payroll.per_unit_amount'))->numeric()->minValue(0)->step(0.01)
                                ->visible(fn (Get $get): bool => $get('salary_model') === 'per_unit')
                                ->required(fn (Get $get): bool => $get('salary_model') === 'per_unit'),
                            Select::make('category')->label(__('employees.payroll.category'))->options(fn (): array => TreatmentCase::categoryOptions())
                                ->searchable()->native(false)->visible(fn (Get $get): bool => in_array($get('salary_model'), ['percentage', 'per_unit'], true)),
                            Select::make('treatment_case_id')->label(__('employees.payroll.manipulation'))->relationship('treatmentCase', 'name')
                                ->searchable()->preload()->native(false)->visible(fn (Get $get): bool => in_array($get('salary_model'), ['percentage', 'per_unit'], true)),
                            DatePicker::make('effective_from')->label(__('employees.payroll.effective_from'))->native(false)->displayFormat('d.m.Y'),
                            Toggle::make('is_active')->label(__('employees.payroll.active'))->default(true),
                            Toggle::make('taxable')->label(__('employees.payroll.taxable'))->default(false)
                                ->hidden(fn (Get $get): bool => $get('source') === 'clinic' && $get('salary_model') === 'fixed_net'),
                            TextInput::make('employee_deductions')->label(__('employees.payroll.deductions'))->numeric()->minValue(0)->step(0.01)->default(0)
                                ->hidden(fn (Get $get): bool => $get('source') === 'clinic' && $get('salary_model') === 'fixed_net'),
                            TextInput::make('employer_cost')->label(__('employees.payroll.employer_cost'))->numeric()->minValue(0)->step(0.01)->default(0)
                                ->hidden(fn (Get $get): bool => $get('source') === 'clinic' && $get('salary_model') === 'fixed_net'),
                            TextInput::make('tax_settings_reference')->label(__('employees.payroll.tax_reference'))->maxLength(255)
                                ->hidden(fn (Get $get): bool => $get('source') === 'clinic' && $get('salary_model') === 'fixed_net'),
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

    private static function positionIsTechnician(mixed $positionId): bool
    {
        return filled($positionId) && (bool) EmployeePosition::query()->whereKey($positionId)->value('is_technician');
    }
}
