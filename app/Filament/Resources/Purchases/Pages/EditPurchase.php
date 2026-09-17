<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected \Filament\Support\Enums\Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
