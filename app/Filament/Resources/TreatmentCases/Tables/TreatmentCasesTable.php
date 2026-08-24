<?php

namespace App\Filament\Resources\TreatmentCases\Tables;

use App\Models\TreatmentCase;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreatmentCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('დასახელება')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('კატეგორია')
                    ->formatStateUsing(fn (string $state): string => TreatmentCase::CATEGORIES[$state] ?? $state)
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('აქტიურია')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('შექმნილია')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('კატეგორია')
                    ->options(TreatmentCase::CATEGORIES),
                TernaryFilter::make('is_active')
                    ->label('აქტიურია')
                    ->placeholder('ყველა')
                    ->trueLabel('აქტიური')
                    ->falseLabel('არააქტიური'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('category')
                ->orderBy('name'));
    }
}
