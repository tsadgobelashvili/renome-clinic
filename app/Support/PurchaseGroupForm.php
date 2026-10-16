<?php

namespace App\Support;

use App\Models\PurchaseProductGroup;
use App\Services\PurchaseCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseGroupForm
{
    public static function schema(bool $lockDirection = false): array
    {
        return [
            TextInput::make('name')->label(__('product-groups.name'))->required()->maxLength(255)
                ->rules([fn ($record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                    if (! app(PurchaseCatalog::class)->groupNameAvailable($value, $record instanceof PurchaseProductGroup ? $record->id : null)) {
                        $fail(__('validation.unique', ['attribute' => __('product-groups.name')]));
                    }
                }]),
            Select::make('expense_direction_id')->label(__('expense-dimensions.direction'))
                ->options(fn () => app(PurchaseCatalog::class)->directionOptions())->required()->searchable()
                ->disabled($lockDirection)->dehydrated(),
        ];
    }

    public static function selector(): Select
    {
        return Select::make('purchase_product_group_id')->label(__('expense-entry.product_group'))->searchable()->live()
            ->options(fn (Get $get) => app(PurchaseCatalog::class)->directionGroupOptions(filled($get('expense_direction_id')) ? (int) $get('expense_direction_id') : null))
            ->disabled(fn (Get $get) => blank($get('expense_direction_id')))
            ->createOptionForm(self::schema(true))
            ->createOptionUsing(function (array $data, Get $get): int {
                if (blank($get('expense_direction_id')) || ($data['expense_direction_id'] ?? null) != $get('expense_direction_id')) {
                    throw ValidationException::withMessages(['expense_direction_id' => __('product-groups.direction_first')]);
                }

                return app(PurchaseCatalog::class)->createGroup($data)->id;
            })
            ->createOptionAction(fn (Action $action, Get $get) => $action
                ->modalHeading(__('product-groups.create'))->modalWidth('sm')
                ->fillForm(['expense_direction_id' => $get('expense_direction_id')])
                ->visible(fn () => filled($get('expense_direction_id')) && Gate::allows('create', PurchaseProductGroup::class)));
    }
}
