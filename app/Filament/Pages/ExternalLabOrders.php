<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Models\Employee;
use App\Models\LabCase;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ExternalLabOrders extends Page implements HasTable
{
    use AuthorizesPageAccess;
    use InteractsWithTable;

    protected string $view = 'filament.pages.external-lab-orders';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return __('lab.external_orders');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationParentItem(): ?string
    {
        return LabCaseResource::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table->query(LabCase::query()->where('source', 'external')
            ->with(['mainWorks.technicianEmployee', 'additionalWorks.technicianEmployee']))
            ->header(view('filament.pages.external-lab-orders-toolbar'))
            ->columns([
                TextColumn::make('case_date')->label(__('lab.date'))->date('d.m.Y')->sortable(),
                TextColumn::make('external_clinic_name')->label(__('lab.external_clinic'))->placeholder('—')->limit(25)->tooltip(fn ($record) => $record->external_clinic_name),
                TextColumn::make('external_doctor_name')->label(__('lab.doctor'))->placeholder('—')->limit(25)->tooltip(fn ($record) => $record->external_doctor_name),
                TextColumn::make('external_patient_name')->label(__('lab.patient'))->placeholder('—')->limit(25)->tooltip(fn ($record) => $record->external_patient_name),
                TextColumn::make('work')->label(__('lab.material'))->state(fn (LabCase $record): string => $record->mainWorks
                    ->map(fn ($work) => LabCase::MATERIALS[$work->material] ?? $work->material)->join(', ') ?: (LabCase::MATERIALS[$record->material] ?? '—')),
                TextColumn::make('qty')->label(__('lab.qty'))->state(fn (LabCase $record): string => $record->mainWorks->pluck('quantity')->join(', ') ?: (string) ($record->quantity ?? '—')),
                TextColumn::make('technician')->label(__('lab.technician'))->state(fn (LabCase $record): string => $record->mainWorks
                    ->map(fn ($work) => $work->technicianEmployee?->full_name)->filter()->unique()->join(', ') ?: '—')->wrap(),
                TextColumn::make('additional')->label(__('lab.additional_work'))->state(fn (LabCase $record): array => $record->additionalWorks
                    ->map(fn ($work): string => __('lab.additional_types.'.$work->work_type).' ×'.$work->quantity
                        .($work->technicianEmployee ? ' — '.$work->technicianEmployee->full_name : '')
                        .($work->note ? ' · '.$work->note : ''))->all())->listWithLineBreaks()->wrap()->placeholder('—'),
                TextColumn::make('notes')->label(__('lab.notes'))->placeholder('—')->limit(45)->tooltip(fn ($record) => $record->notes),
            ])->filters([
                Filter::make('review')->schema([
                    DatePicker::make('from')->label(__('employees.salary.from'))->default(fn () => today()->subDays(9))->displayFormat('d.m.Y'),
                    DatePicker::make('until')->label(__('employees.salary.until'))->default(fn () => today())->displayFormat('d.m.Y'),
                    Select::make('clinic')->hiddenLabel()->label(__('lab.clinic'))->placeholder(__('lab.clinic'))->options(fn () => ['' => __('lab.all')] + LabCase::where('source', 'external')
                        ->whereNotNull('external_clinic_name')->where('external_clinic_name', '!=', '')
                        ->distinct()->orderBy('external_clinic_name')->pluck('external_clinic_name', 'external_clinic_name')->all())->searchable(),
                    TextInput::make('doctor')->hiddenLabel()->label(__('lab.doctor'))->placeholder(__('lab.doctor_placeholder'))->live(debounce: 300),
                    TextInput::make('patient')->hiddenLabel()->label(__('lab.patient'))->placeholder(__('lab.patient_placeholder'))->live(debounce: 300),
                    Select::make('technician')->hiddenLabel()->label(__('lab.technician'))->placeholder(__('lab.technicians_placeholder'))->searchable()->options(fn () => ['' => __('lab.all')] + Employee::query()
                        ->where(fn ($q) => $q->whereHas('mainLabWorks.labCase', fn ($case) => $case->where('source', 'external'))
                            ->orWhereHas('additionalLabWorks.labCase', fn ($case) => $case->where('source', 'external')))
                        ->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($employee) => [$employee->id => $employee->full_name])->all()),
                    Select::make('material')->hiddenLabel()->label(__('lab.material'))->placeholder(__('lab.all_materials'))->options(LabCase::MATERIALS),
                ])->columns(['default' => 1, 'sm' => 2, 'md' => 3, 'xl' => 7])->columnSpanFull()
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('case_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('case_date', '<=', $date))
                        ->when($data['clinic'] ?? null, fn ($q, $clinic) => $q->where('external_clinic_name', $clinic))
                        ->when(filled($data['doctor'] ?? null), fn ($q) => $q->whereRaw('LOWER(external_doctor_name) LIKE ?', ['%'.mb_strtolower(trim($data['doctor'])).'%']))
                        ->when(filled($data['patient'] ?? null), fn ($q) => $q->whereRaw('LOWER(external_patient_name) LIKE ?', ['%'.mb_strtolower(trim($data['patient'])).'%']))
                        ->when($data['material'] ?? null, fn ($q, $material) => $q->where(fn ($q) => $q
                            ->whereHas('mainWorks', fn ($work) => $work->where('material', $material))
                            ->orWhere(fn ($q) => $q->whereDoesntHave('mainWorks')->where('material', $material))))
                        ->when($data['technician'] ?? null, fn ($q, $technician) => $q->where(fn ($q) => $q
                            ->whereHas('mainWorks', fn ($work) => $work->where('technician_id', $technician))
                            ->orWhereHas('additionalWorks', fn ($work) => $work->where('technician_id', $technician))))),
            ], layout: FiltersLayout::Hidden)->filtersFormColumns(1)->deferFilters(false)
            ->defaultSort('case_date', 'desc')->striped()->paginationPageOptions([10, 25, 50]);
    }
}
