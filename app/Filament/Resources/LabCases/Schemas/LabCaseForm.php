<?php

namespace App\Filament\Resources\LabCases\Schemas;

use App\Models\Employee;
use App\Models\LabMainWork;
use App\Services\LabPartyAutocomplete;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class LabCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('case_date_display')->hiddenLabel()->content(fn (Get $get): string => filled($get('case_date')) ? Carbon::parse($get('case_date'))->format('d.m.Y') : today()->format('d.m.Y'))
                ->visible(fn (string $operation): bool => $operation !== 'create')
                ->extraAttributes(['class' => 'renome-lab-date']),
            Hidden::make('case_date')->default(fn (): string => today()->toDateString())->required(),
            Hidden::make('doctor_id'),
            Hidden::make('patient_id'),
            Hidden::make('patient_entry'),
            Radio::make('source')->label(__('lab.source'))->options([
                'clinic' => __('lab.sources.clinic'),
                'israeli' => __('lab.sources.israeli'),
                'external' => __('lab.sources.external'),
            ])->default('clinic')->live()->required()->view('filament.resources.lab-cases.source-segments')
                ->extraAttributes(['class' => 'renome-lab-source']),
            Repeater::make('mainWorks')->label(__('lab.main_work'))->relationship()->defaultItems(1)->minItems(1)
                ->extraFieldWrapperAttributes(['class' => 'renome-lab-work-section'])
                ->afterLabel(fn (Repeater $component) => new HtmlString($component->getAction('add')->toHtml()))
                ->schema([
                    TextInput::make('doctor_search')->label(__('lab.doctor'))
                        ->placeholder(__('lab.doctor_placeholder'))->live(debounce: 200)->dehydrated(false)
                        ->datalist(fn (Get $get): array => app(LabPartyAutocomplete::class)->doctorSuggestions($get('doctor_search')))
                        ->afterStateHydrated(fn (TextInput $component, ?LabMainWork $record) => $component->state($record?->labCase?->doctor?->full_name))
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('../../doctor_id', app(LabPartyAutocomplete::class)->doctorIdFromLabel($state))),
                    TextInput::make('patient_search')->label(__('lab.patient'))
                        ->placeholder(__('lab.patient_placeholder'))->live(debounce: 200)->dehydrated(false)
                        ->datalist(fn (Get $get): array => app(LabPartyAutocomplete::class)->patientSuggestions($get('patient_search')))
                        ->afterStateHydrated(fn (TextInput $component, ?LabMainWork $record) => $component->state($record?->labCase?->patient?->lab_selection_label))
                        ->afterStateUpdated(function (?string $state, Set $set, string $operation): void {
                            $patient = app(LabPartyAutocomplete::class)->patientFromLabel($state);
                            $set('../../patient_entry', $state);
                            $set('../../patient_id', $patient?->getKey());
                            if ($patient && $operation === 'create') {
                                $set('../../source', app(LabPartyAutocomplete::class)->sourceForPatient($patient->getKey()));
                            }
                        }),
                    Select::make('material')->label(__('lab.material'))->native(false)->options([
                        'pmma' => 'PMMA', 'zircon' => 'Zircon', 'other' => __('lab.materials.other'),
                    ])->required(),
                    TextInput::make('quantity')->label(__('lab.qty'))->numeric()->minValue(1)->default(1)->required(),
                    TextInput::make('shade')->label(__('lab.shade'))->maxLength(255),
                    Select::make('technician_id')->label(__('lab.technician'))->native(false)->searchable()
                        ->options(fn (): array => Employee::query()->activeTechnicians()->orderBy('first_name')->orderBy('last_name')
                            ->get()->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->full_name])->all()),
                ])->table([
                    TableColumn::make(__('lab.doctor'))->width('18%'),
                    TableColumn::make(__('lab.patient'))->width('22%'),
                    TableColumn::make(__('lab.material'))->width('18%'),
                    TableColumn::make(__('lab.qty'))->width('10%'),
                    TableColumn::make(__('lab.shade'))->width('10%'),
                    TableColumn::make(__('lab.technician'))->width('17%'),
                    TableColumn::make('')->width('5%'),
                ])->reorderable(false)->compact()
                ->addAction(fn (Action $action): Action => $action->label(__('lab.add_work'))->link()->size('sm')
                    ->after(function (Repeater $component): void {
                        $items = $component->getRawState();
                        if (count($items) < 2) {
                            return;
                        }

                        $previous = array_values($items)[count($items) - 2];
                        $lastKey = array_key_last($items);
                        $material = match ($previous['material'] ?? null) {
                            'pmma' => 'zircon',
                            'zircon' => 'pmma',
                            default => null,
                        };
                        $items[$lastKey] = [...$items[$lastKey],
                            'doctor_search' => $previous['doctor_search'] ?? null,
                            'patient_search' => $previous['patient_search'] ?? null,
                            'material' => $material,
                            'quantity' => $previous['quantity'] ?? 1,
                            'shade' => $previous['shade'] ?? null,
                            'technician_id' => $previous['technician_id'] ?? null,
                        ];
                        $component->rawState($items);
                    }))
                ->extraAttributes(['class' => 'renome-lab-main-works'])->columnSpanFull(),

            Repeater::make('additionalWorks')->label(__('lab.additional_work'))->relationship()
                ->extraFieldWrapperAttributes(['class' => 'renome-lab-work-section'])
                ->afterLabel(fn (Repeater $component) => new HtmlString($component->getAction('add')->toHtml()))
                ->defaultItems(0)->columns(4)->compact()->reorderable(false)
                ->addAction(fn (Action $action): Action => $action->label(__('lab.add_additional_work'))->link()->size('sm'))
                ->schema([
                    Select::make('work_type')->label(__('lab.work_type'))->options([
                        'milling' => __('lab.additional_types.milling'),
                        'individual_abutment' => __('lab.additional_types.individual_abutment'),
                        'titanium_bar_modeling' => __('lab.additional_types.titanium_bar_modeling'),
                        'other' => __('lab.additional_types.other'),
                    ])->native(false)->required(),
                    TextInput::make('quantity')->label(__('lab.qty'))->numeric()->minValue(1)->default(1)->required(),
                    Select::make('technician_id')->label(__('lab.technician'))->native(false)->searchable()
                        ->options(fn (): array => Employee::query()->activeTechnicians()->orderBy('first_name')->orderBy('last_name')
                            ->get()->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->full_name])->all()),
                    TextInput::make('note')->label(__('lab.note'))->maxLength(1000),
                ])->table([
                    TableColumn::make(__('lab.work_type'))->width('30%'),
                    TableColumn::make(__('lab.qty'))->width('12%'),
                    TableColumn::make(__('lab.technician'))->width('28%'),
                    TableColumn::make(__('lab.note'))->width('25%'),
                    TableColumn::make('')->width('5%'),
                ])->extraAttributes(['class' => 'renome-lab-additional'])->columnSpanFull(),

            Textarea::make('notes')->label(__('lab.notes'))->rows(3)->maxLength(1000)->columnSpanFull(),
        ])->columns(1)->extraAttributes(['class' => 'renome-lab-form']);
    }
}
