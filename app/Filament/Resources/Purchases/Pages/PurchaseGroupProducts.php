<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

class PurchaseGroupProducts extends PurchaseProductMappings
{
    #[Locked]
    public int $groupId;

    public function mount(int $group): void
    {
        static::authorizeResourceAccess();
        $this->groupId = PurchaseProductGroup::findOrFail($group)->id;
    }

    public function getTitle(): string
    {
        return PurchaseProductGroup::findOrFail($this->groupId)->name;
    }

    protected function productQuery(): Builder
    {
        return PurchaseProduct::query()->where('purchase_product_group_id', $this->groupId);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('groups')->label('პროდუქციის ჯგუფები')->url(static::getResource()::getUrl('groups'))];
    }
}
