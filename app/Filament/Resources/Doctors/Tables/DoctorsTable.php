<?php

namespace App\Filament\Resources\Doctors\Tables;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Models\Doctor;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->label('სახელი')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label('გვარი')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('ტელეფონი')
                    ->searchable(),

                TextColumn::make('specialty')
                    ->label('სპეციალობა')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('აქტიური')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('დამატებულია')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('განახლებულია')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('ახალი ექიმი')
                    ->icon('heroicon-o-plus')
                    ->color('primary'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn (Doctor $record): string => DoctorResource::getUrl('view', [
                'record' => $record,
            ]))
            ->defaultSort('first_name', 'asc');
    }
}
