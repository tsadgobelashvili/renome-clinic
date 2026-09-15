<?php

namespace App\Filament\Resources\LabCases\Tables;

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Services\LabPartyAutocomplete;
use App\Models\LabCase;
use App\Support\LabTechnicianDisplay;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class LabCasesTable
{
    public static function configure(Table $table): Table
    {
        $names = new LabTechnicianDisplay;

        return $table->columns([
            TextColumn::make('case_date')->label(__('lab.date'))->date('d.m.Y')->sortable(),
            TextColumn::make('doctor_display')->label(__('lab.doctor'))->searchable(['external_doctor_name']),
            TextColumn::make('patient_display')->label(__('lab.patient'))->searchable(query: fn ($query, string $search) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(external_patient_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereHas('patient', fn ($patient) => $patient->searchForLab($search)))),
            TextColumn::make('main_works_materials')->label(__('lab.material'))->state(fn (LabCase $record): string => $record->mainWorks
                ->map(fn ($work): string => LabCase::MATERIALS[$work->material] ?? $work->material)->join(', ') ?: '—'),
            TextColumn::make('main_works_quantities')->label(__('lab.qty'))->state(fn (LabCase $record): string => $record->mainWorks->pluck('quantity')->join(', ') ?: '—'),
            TextColumn::make('main_works_shades')->label(__('lab.shade'))->state(fn (LabCase $record): string => $record->mainWorks->pluck('shade')->filter()->join(', ') ?: '—'),
            TextColumn::make('modeler_display')->label(__('lab.modeling'))
                ->state(fn (LabCase $record): string => $names->modeler($record))->wrap(),
            TextColumn::make('additional_work_summary')->label(__('lab.additional_work'))
                ->state(fn (LabCase $record): array => $record->additionalWorks->map(function ($work) use ($names): string {
                    $type = in_array($work->work_type, ['milling', 'individual_abutment', 'titanium_bar_modeling'])
                        ? __('lab.additional_types_short.'.$work->work_type) : __('lab.additional_types.'.$work->work_type);
                    $technician = $names->name($work->technicianEmployee, $work->technician);

                    return $type.' ×'.$work->quantity.' — '.$technician;
                })->all())->listWithLineBreaks()->wrap()->placeholder('—'),
            TextColumn::make('source')->label(__('lab.source'))->badge()->formatStateUsing(fn (?string $state): string => $state ? __('lab.sources.'.$state) : '—'),
        ])->header(view('filament.resources.lab-cases.table-toolbar'))
            ->filters([
                Filter::make('toolbar')->schema([Grid::make()->extraAttributes(['class' => 'renome-lab-filter-fields'])->schema([
                    DatePicker::make('from')->hiddenLabel()->placeholder(__('employees.salary.from'))->native(false)->format('Y-m-d')->displayFormat('d.m.Y')
                        ->extraFieldWrapperAttributes(['class' => 'renome-lab-filter-date'])->default(fn (): string => today()->subDays(9)->toDateString()),
                    DatePicker::make('until')->hiddenLabel()->placeholder(__('employees.salary.until'))->native(false)->format('Y-m-d')->displayFormat('d.m.Y')
                        ->extraFieldWrapperAttributes(['class' => 'renome-lab-filter-date'])->default(fn (): string => today()->toDateString()),
                    Select::make('source')->hiddenLabel()->default('all')->selectablePlaceholder(false)->extraFieldWrapperAttributes(['class' => 'renome-lab-filter-source'])
                        ->options(['all' => __('lab.all'), ...collect(LabCase::SOURCES)->mapWithKeys(fn ($label, $key) => [$key => __('lab.sources.'.$key)])->all()])->native(false),
                    Select::make('doctor_id')->hiddenLabel()->placeholder(__('lab.doctor').' — '.__('lab.all'))
                        ->options(fn (): array => app(LabPartyAutocomplete::class)->practitionerOptions())
                        ->getSearchResultsUsing(fn (string $search): array => app(LabPartyAutocomplete::class)->practitionerOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => app(LabPartyAutocomplete::class)->practitionerOptionLabel($value))
                        ->extraFieldWrapperAttributes(['class' => 'renome-lab-filter-doctor'])->searchable()->native(false),
                ])])->columns(1)->columnSpanFull()
                    ->query(fn ($query, array $data) => $query
                        ->when($data['doctor_id'] ?? null, fn ($q, $id) => str_starts_with((string) $id, 'employee:')
                            ? $q->where('assistant_employee_id', substr($id, 9)) : $q->where('doctor_id', $id))
                        ->when(filled($data['source'] ?? null) && $data['source'] !== 'all', fn ($q) => $q->where('source', $data['source']))
                        ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('case_date', '>=', substr($from, 0, 10)))
                        ->when($data['until'] ?? null, fn ($q, $until) => $q->whereDate('case_date', '<=', substr($until, 0, 10)))),
            ], FiltersLayout::Hidden)->filtersFormColumns(1)->deferFilters(false)->searchable(false)
            ->recordActions([])
            ->recordUrl(fn (LabCase $record): ?string => LabCaseResource::canEdit($record) ? LabCaseResource::getUrl('edit', ['record' => $record]) : null)
            ->defaultSort('case_date', 'desc')->striped();
    }
}
