<?php

namespace App\Filament\Resources\TreatmentEstimates\Tables;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\TreatmentEstimate;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TreatmentEstimatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('estimate_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('patient.full_name')->label('პაციენტი')->searchable(['first_name', 'last_name']),
                TextColumn::make('doctor.full_name')->label('ექიმი')->placeholder('—'),
                TextColumn::make('options_count')->label('ვარიანტები')->counts('options'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn (TreatmentEstimate $record): string => TreatmentEstimateResource::getUrl('view', [
                'record' => $record,
            ]))
            ->defaultSort('estimate_date', 'desc');
    }
}
