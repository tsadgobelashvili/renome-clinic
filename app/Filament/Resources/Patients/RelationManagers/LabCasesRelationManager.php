<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LabCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'labCases';
    protected static ?string $title = 'Laboratory history';
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('patient-profile.laboratory');
    }
    public static function canViewForRecord($ownerRecord, string $pageClass): bool { return auth()->user()?->isOwner() ?? false; }
    public function table(Table $table): Table { return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
        'doctor', 'assistantEmployee', 'workItems.technician', 'mainWorks.technicianEmployee',
        'additionalWorks.technicianEmployee', 'modeler', 'miller',
    ]))->columns([
        TextColumn::make('case_date')->label(__('lab.date'))->date('d.m.Y')->placeholder('—'),
        TextColumn::make('doctor_display')->label(__('lab.doctor'))->placeholder('—')->wrap(),
        ViewColumn::make('work_details')->label(__('lab.work_details'))->view('filament.resources.patients.lab-work-details'),
        TextColumn::make('exocad_project_reference')->label(__('lab.exocad'))->searchable()->placeholder('—')->wrap(),
        TextColumn::make('status')->label(__('lab.status'))->badge()->placeholder('—')
            ->formatStateUsing(fn (string $state): string => \Illuminate\Support\Facades\Lang::has('patient-profile.lab_statuses.'.$state)
                ? __('patient-profile.lab_statuses.'.$state) : $state),
    ])->defaultSort('case_date', 'desc'); }
}
