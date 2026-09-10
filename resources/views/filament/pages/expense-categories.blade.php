<x-filament-panels::page>
    <div class="space-y-3">
        <x-filament::button wire:click="edit">{{ __('expense-categories.add_category') }}</x-filament::button>
        <p class="text-sm text-gray-500">{{ __('expense-categories.delete_help') }}</p>
        @if($editing)
            <form wire:submit="save" class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <label class="block text-sm">{{ __('expense-categories.name') }}
                    <x-filament::input.wrapper><x-filament::input wire:model="name" required maxlength="255" /></x-filament::input.wrapper>
                </label>
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <label class="block text-sm">{{ __('expense-categories.sort_order') }}
                    <x-filament::input.wrapper><x-filament::input type="number" wire:model="sortOrder" min="0" max="100000" /></x-filament::input.wrapper>
                </label>
                @error('sortOrder') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <label class="text-sm"><input type="checkbox" wire:model="active"> {{ __('expense-categories.active') }}</label>
                <div class="flex gap-2">
                    <x-filament::button type="submit">{{ __('expense-categories.save') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="$set('editing', false)">{{ __('expense-categories.cancel') }}</x-filament::button>
                </div>
            </form>
        @endif
        @foreach($categories as $category)
            <details wire:key="category-{{ $category->id }}" class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <summary class="cursor-pointer text-sm font-semibold">{{ $category->name }} · {{ $category->subcategories->count() }}
                    @unless($category->active) — {{ __('expense-categories.inactive') }} @endunless
                </summary>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-filament::button size="xs" wire:click="edit(null, {{ $category->id }})">{{ __('expense-categories.add_subcategory') }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="edit({{ $category->id }})">{{ __('expense-categories.edit') }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="toggleActive({{ $category->id }})">{{ __('expense-categories.'.($category->active ? 'deactivate' : 'activate')) }}</x-filament::button>
                    <x-filament::button size="xs" color="danger" wire:confirm="{{ __('expense-categories.delete_help') }}" wire:click="deleteRecord({{ $category->id }})">{{ __('expense-categories.delete') }}</x-filament::button>
                </div>
                @foreach($category->subcategories as $subcategory)
                    <div wire:key="subcategory-{{ $subcategory->id }}" class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-100 py-2 text-sm dark:border-gray-800">
                        <span class="grow">{{ $subcategory->name }} @unless($subcategory->active) — {{ __('expense-categories.inactive') }} @endunless</span>
                        <x-filament::button size="xs" color="gray" wire:click="edit({{ $subcategory->id }}, {{ $category->id }})">{{ __('expense-categories.edit') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="toggleActive({{ $subcategory->id }}, true)">{{ __('expense-categories.'.($subcategory->active ? 'deactivate' : 'activate')) }}</x-filament::button>
                        <x-filament::button size="xs" color="danger" wire:confirm="{{ __('expense-categories.delete_help') }}" wire:click="deleteRecord({{ $subcategory->id }}, true)">{{ __('expense-categories.delete') }}</x-filament::button>
                    </div>
                @endforeach
            </details>
        @endforeach
    </div>
</x-filament-panels::page>
