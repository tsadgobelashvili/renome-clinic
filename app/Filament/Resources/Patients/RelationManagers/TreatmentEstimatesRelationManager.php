<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\TreatmentEstimates\Actions\CreateTreatmentEstimateAction;
use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\TreatmentEstimate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreatmentEstimatesRelationManager extends RelationManager
{
    protected static string $relationship = 'treatmentEstimates';

    protected static ?string $title = 'მკურნალობის გეგმები';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['doctor', 'options.items'])->withCount('options'))
            ->columns([
                TextColumn::make('estimate_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('doctor.full_name')->label('ექიმი')->placeholder('—'),
                TextColumn::make('options_count')->label('ვარიანტები'),
            ])
            ->headerActions([
                CreateTreatmentEstimateAction::make(fn (): int => $this->getOwnerRecord()->getKey()),
            ])
            ->recordUrl(fn (TreatmentEstimate $record): string => TreatmentEstimateResource::getUrl('view', [
                'record' => $record,
            ]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->defaultSort('estimate_date', 'desc');
    }
}
