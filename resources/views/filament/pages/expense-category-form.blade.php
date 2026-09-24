            <form wire:submit="save" class="space-y-2 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="truncate text-sm font-medium">{{ $parentId ? $categories->firstWhere('id', $parentId)?->name.' → '.__('expense-dimensions.type') : __('expense-dimensions.direction') }}</div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_7rem]">
                    <label class="min-w-0 text-xs">{{ __('expense-categories.name') }}<x-filament::input.wrapper><x-filament::input wire:model="name" required maxlength="255" /></x-filament::input.wrapper></label>
                    <label class="text-xs">{{ __('expense-categories.sort_order') }}<x-filament::input.wrapper><x-filament::input type="number" wire:model="sortOrder" min="0" max="100000" /></x-filament::input.wrapper></label>
                </div>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="active"> {{ __('expense-categories.active') }}</label>
                @foreach($errors->all() as $error)<p class="text-xs text-red-600">{{ $error }}</p>@endforeach
                <div class="flex gap-2"><x-filament::button size="sm" type="submit">{{ __('expense-categories.save') }}</x-filament::button><x-filament::button size="sm" color="gray" wire:click="$set('editing', false)">{{ __('expense-categories.cancel') }}</x-filament::button></div>
            </form>
