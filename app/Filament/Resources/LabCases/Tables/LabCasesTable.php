<?php

namespace App\Filament\Resources\LabCases\Tables;

use App\Models\LabWorkItem;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LabCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('case_date')->label(__('lab.date'))->date('d.m.Y')->sortable(),
            TextColumn::make('patient.full_name')->label(__('lab.patient'))->searchable(['first_name', 'last_name']),
            TextColumn::make('doctor.full_name')->label(__('lab.doctor'))->placeholder('—'),
            TextColumn::make('exocad_project_reference')->label(__('lab.exocad'))->searchable()->placeholder('—'),
            TextColumn::make('workItems.work_type')->label(__('lab.works'))->badge()->formatStateUsing(fn ($state) => LabWorkItem::WORK_TYPES[$state] ?? $state),
            TextColumn::make('status')->label(__('lab.status'))->badge(),
        ])->recordActions([EditAction::make()])->defaultSort('case_date', 'desc');
    }
}
