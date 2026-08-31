<?php

namespace App\Filament\Resources\Purchases\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('purchase_date')->label('თარიღი')->date('d.m.Y')->sortable(),
            TextColumn::make('supplier.name')->label('მომწოდებელი')->searchable()->sortable(),
            TextColumn::make('items.product.name')->label('პროდუქტი / მასალა')->listWithLineBreaks()->limitList(3)->expandableLimitedList()->searchable(),
            TextColumn::make('items.product.category.name')->label('კატეგორია')->badge()->separator(','),
            TextColumn::make('items.quantity')->label('რაოდენობა')->listWithLineBreaks(),
            TextColumn::make('document_number')->label('დოკუმენტის №')->placeholder('—')->searchable(),
            TextColumn::make('source')->label('წყარო')->badge()->placeholder('Manual'),
            TextColumn::make('total_amount')->label('სრული თანხა')->money('GEL')->alignEnd()->sortable(),
        ])->filters([
            SelectFilter::make('supplier_id')->label('მომწოდებელი')->relationship('supplier', 'name')->searchable()->preload(),
            SelectFilter::make('category')->label('კატეგორია')->relationship('items.product.category', 'name')->searchable()->preload(),
        ])->recordActions([EditAction::make()])->defaultSort('purchase_date', 'desc');
    }
}
