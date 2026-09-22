<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreatmentCases extends ListRecords
{
    protected static string $resource = TreatmentCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('categories')->label('კატეგორიები')->color('gray')->url(TreatmentCaseResource::getUrl('categories')),
            Action::make('groups')->label('სტატისტიკის ჯგუფები')->color('gray')->url(TreatmentCaseResource::getUrl('groups')),
            Action::make('uncategorized')->label('დაუჯგუფებელი პროცედურები')
                ->color('gray')->url(TreatmentCaseResource::getUrl('uncategorized')),
            CreateAction::make(),
        ];
    }
}
