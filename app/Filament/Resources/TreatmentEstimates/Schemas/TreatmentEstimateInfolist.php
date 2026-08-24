<?php

namespace App\Filament\Resources\TreatmentEstimates\Schemas;

use App\Models\TreatmentEstimateOption;
use App\Models\TreatmentEstimateStage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TreatmentEstimateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('progress_planned')->label('დაგეგმილი')
                    ->state(fn ($record): string => self::money($record->getProgressSummary()['planned_amount'])),
                TextEntry::make('progress_executed')->label('შესრულებული')
                    ->state(fn ($record): string => self::money($record->getProgressSummary()['executed_amount'])),
                TextEntry::make('progress_paid')->label('გადახდილი')
                    ->state(fn ($record): string => self::money($record->getProgressSummary()['paid_amount'])),
                TextEntry::make('progress_remaining')->label('დარჩენილი')
                    ->state(fn ($record): string => self::money($record->getProgressSummary()['remaining_amount'])),
            ])->compact()->columns(4),
            Section::make('მკურნალობის გეგმა')->schema([
                TextEntry::make('patient.full_name')->label('პაციენტი'),
                TextEntry::make('doctor.full_name')->label('ექიმი')->placeholder('—'),
                TextEntry::make('estimate_date')->label('თარიღი')->date('d.m.Y'),
            ])->columns(3),
            RepeatableEntry::make('options')->label('ვარიანტები')->schema([
                TextEntry::make('name')->label('ვარიანტი')->placeholder('უსახელო ვარიანტი'),
                TextEntry::make('estimated_duration')->label('სავარაუდო დრო')->placeholder('—'),
                RepeatableEntry::make('stages')->hiddenLabel()->schema([
                    TextEntry::make('name')->label('ეტაპი')
                        ->visible(fn (TreatmentEstimateStage $record): bool => $record->option->stages->count() > 1),
                    RepeatableEntry::make('items')->label('მანიპულაციები')->schema([
                        TextEntry::make('description')->label('მანიპულაცია'),
                        TextEntry::make('quantity')->label('რაოდენობა'),
                        TextEntry::make('unit_price')->label('ერთეულის ფასი')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' ₾'),
                        TextEntry::make('line_total')->label('ჯამი')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' ₾'),
                    ])->columns(4)->columnSpanFull(),
                    TextEntry::make('subtotal')->label('ეტაპის ჯამი')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' ₾'),
                ])->columns(2)->columnSpanFull(),
                TextEntry::make('total_amount')->label('ჯამი')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' ₾'),
                TextEntry::make('discount_display')->label('ფასდაკლება')
                    ->visible(fn (TreatmentEstimateOption $record): bool => $record->discount_amount > 0),
                TextEntry::make('final_amount')->label('საბოლოო თანხა')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' ₾'),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2).' ₾';
    }
}
