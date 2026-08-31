<?php

namespace App\Filament\Resources\LabCases\Pages;

use App\Filament\Resources\LabCases\LabCaseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListLabCases extends ListRecords
{
    protected static string $resource = LabCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('language')->icon('heroicon-o-language')->form([Select::make('locale')->options(['ka' => 'ქართული', 'en' => 'English'])->default(fn () => auth()->user()->locale)->required()])->action(function (array $data): void {
            auth()->user()->update(['locale' => $data['locale']]);
            $this->redirect(request()->header('Referer') ?: LabCaseResource::getUrl());
        }), CreateAction::make()];
    }
}
