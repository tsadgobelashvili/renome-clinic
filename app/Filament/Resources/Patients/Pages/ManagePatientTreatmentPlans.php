<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\TreatmentEstimate;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManagePatientTreatmentPlans extends ManageRelatedRecords
{
    protected static string $resource = PatientResource::class;

    protected static string $relationship = 'treatmentEstimates';

    protected static ?string $relatedResource = TreatmentEstimateResource::class;

    protected static ?string $title = 'მკურნალობის გეგმები';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['doctor', 'options.items']))
            ->columns([
                TextColumn::make('estimate_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('doctor.full_name')->label('ექიმი')->placeholder('—'),
                TextColumn::make('plan_amount')->label('თანხა')
                    ->state(function (TreatmentEstimate $record): string {
                        $amounts = $record->options->map->final_amount->sort()->values();

                        if ($amounts->isEmpty()) {
                            return '0.00 ₾';
                        }

                        if ($amounts->count() === 1 || $amounts->first() === $amounts->last()) {
                            return number_format((float) $amounts->first(), 2).' ₾';
                        }

                        return number_format((float) $amounts->first(), 2).'–'
                            .number_format((float) $amounts->last(), 2).' ₾';
                    }),
            ])
            ->headerActions([
                Action::make('createPlan')->label('+ გეგმა')
                    ->url(fn (): string => TreatmentEstimateResource::getUrl('create', [
                        'patient_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                ViewAction::make()->label('გახსნა'),
                EditAction::make()->label('რედაქტირება'),
            ])
            ->recordUrl(fn (TreatmentEstimate $record): string => TreatmentEstimateResource::getUrl('view', [
                'record' => $record,
            ]))
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->defaultSort('estimate_date', 'desc');
    }
}
