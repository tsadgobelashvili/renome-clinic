<?php

namespace App\Filament\Resources\LabCases\Schemas;

use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabWorkItem;
use App\Models\Patient;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class LabCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Select::make('patient_id')->label(__('lab.patient'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Patient::query()->searchForClinic($search)->limit(50)->get()->mapWithKeys(fn ($p) => [$p->id => $p->full_name])->all())
                    ->getOptionLabelUsing(fn ($value) => Patient::find($value)?->full_name)->required()
                    ->createOptionForm([TextInput::make('first_name')->required(), TextInput::make('last_name')->required(), TextInput::make('phone'), DatePicker::make('birth_date'), TextInput::make('personal_id')])
                    ->createOptionUsing(fn (array $data): int => Patient::create($data)->getKey()),
                Select::make('doctor_id')->label(__('lab.doctor'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Doctor::query()->searchByName($search)->limit(50)->get()->mapWithKeys(fn ($d) => [$d->id => $d->full_name])->all())
                    ->getOptionLabelUsing(fn ($value) => Doctor::find($value)?->full_name),
                DatePicker::make('case_date')->label(__('lab.date'))->default(now())->required(),
                Select::make('status')->label(__('lab.status'))->options(LabCase::STATUSES)->default('open')->required(),
                TextInput::make('exocad_project_reference')->label(__('lab.exocad'))->maxLength(255),
                Select::make('related_case_id')->label(__('lab.related_case'))->searchable()->relationship('relatedCase', 'exocad_project_reference'),
                Select::make('case_relationship')->label(__('lab.relationship'))->options(['same_case' => __('lab.same_case'), 'new_case' => __('lab.new_case')])->visible(fn (Get $get) => filled($get('related_case_id'))),
            ])->columnSpanFull(),
            Textarea::make('notes')->label(__('lab.notes'))->columnSpanFull(),
            Section::make(__('lab.works'))->schema([
                Repeater::make('workItems')->relationship(
                    modifyQueryUsing: fn (Builder $query): Builder => auth()->user()?->isOwner()
                        ? $query
                        : $query->where('technician_id', auth()->id()),
                )->defaultItems(1)->columns(6)->schema([
                    Select::make('work_type')->label(__('lab.work_type'))->options(LabWorkItem::WORK_TYPES)->required(),
                    Select::make('component_type')->label(__('lab.component'))->options(LabWorkItem::COMPONENT_TYPES)->required(),
                    TextInput::make('quantity')->label(__('lab.quantity'))->numeric()->minValue(1)->default(1)->required(),
                    Select::make('technician_id')->label(__('lab.technician'))
                        ->options(fn () => auth()->user()?->isOwner()
                            ? User::query()->where('role', User::ROLE_LAB_TECHNICIAN)->orderBy('name')->pluck('name', 'id')
                            : User::query()->whereKey(auth()->id())->pluck('name', 'id'))
                        ->default(fn () => auth()->user()?->isOwner() ? null : auth()->id())
                        ->disabled(fn () => ! auth()->user()?->isOwner())->dehydrated()->searchable()->required(),
                    DatePicker::make('work_date')->label(__('lab.date'))->default(now())->required(),
                    Select::make('status')->label(__('lab.status'))->options(['pending' => 'Pending', 'completed' => 'Completed'])->default('completed')->required(),
                ]),
            ])->columnSpanFull(),
        ]);
    }
}
