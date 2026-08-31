<?php

namespace App\Filament\Resources\LabCases\Pages;

use App\Filament\Resources\LabCases\LabCaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLabCase extends EditRecord
{
    protected static string $resource = LabCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => auth()->user()?->isOwner())];
    }
}
