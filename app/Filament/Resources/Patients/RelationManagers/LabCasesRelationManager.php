<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LabCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'labCases';
    protected static ?string $title = 'Laboratory history';
    public static function canViewForRecord($ownerRecord, string $pageClass): bool { return auth()->user()?->isOwner() ?? false; }
    public function table(Table $table): Table { return $table->modifyQueryUsing(fn ($q) => $q->with(['doctor', 'workItems.technician']))->columns([
        TextColumn::make('case_date')->date('d.m.Y'), TextColumn::make('doctor.full_name')->placeholder('—'),
        TextColumn::make('exocad_project_reference')->searchable()->placeholder('—'), TextColumn::make('workItems.work_type')->badge(), TextColumn::make('status')->badge(),
    ])->defaultSort('case_date', 'desc'); }
}
