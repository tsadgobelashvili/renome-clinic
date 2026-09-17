<x-filament-panels::page>
    <div class="w-full space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button size="sm" icon="heroicon-m-plus" wire:click="edit">{{ __('expense-categories.add_category') }}</x-filament::button>
            <x-filament::button size="sm" tag="a" :href="\App\Filament\Pages\BankRules::getUrl()" color="gray">{{ __('bank-accounting.rules') }}</x-filament::button>
        </div>
        <p class="text-xs text-gray-500">{{ __('expense-categories.delete_help') }}</p>
        @if($editing)
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
        @endif
        <div class="space-y-1.5">
            @foreach($categories as $category)
                <div wire:key="expense-parent-{{ $category->id }}" class="relative rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-gray-900">
                    <details @if($parentId === $category->id) open @endif class="group rounded-lg open:bg-teal-50/20 dark:open:bg-white/5">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg py-2 pl-3 pr-12 text-sm hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-teal-600 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
                            <x-filament::icon icon="heroicon-m-chevron-right" class="size-4 shrink-0 text-gray-400 transition-transform duration-150 group-open:rotate-90" />
                            <span class="min-w-0 truncate font-semibold text-gray-800 dark:text-gray-100" title="{{ $category->name }}">{{ \App\Services\ExpenseDimensions::label($category) }}</span>
                            <span class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-white/10">{{ $category->children->count() }}</span>
                            @unless($category->active)<span class="shrink-0 text-xs text-gray-400">{{ __('expense-categories.inactive') }}</span>@endunless
                        </summary>
                        <div class="px-3 pb-2">
                            <div class="mb-1 ml-5">
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-plus" wire:click="edit(null, {{ $category->id }})">{{ __('expense-dimensions.type') }}</x-filament::button>
                            </div>
                            <div class="ml-2 border-l border-gray-200/80 pl-3 dark:border-white/10">
                                @forelse($category->children as $child)
                                    <div wire:key="expense-child-{{ $child->id }}" class="flex min-h-8 items-center gap-2 rounded-md py-0.5 pl-1 text-sm hover:bg-gray-100/60 dark:hover:bg-white/5">
                                        <span class="min-w-0 flex-1 truncate text-gray-600 dark:text-gray-300" title="{{ $child->name }}">{{ \App\Services\ExpenseDimensions::label($child) }}</span>
                                        @unless($child->active)<span class="shrink-0 text-xs text-gray-400">{{ __('expense-categories.inactive') }}</span>@endunless
                                        @include('filament.pages.expense-category-actions', ['record' => $child, 'parent' => $category->id])
                                    </div>
                                @empty
                                    <p class="py-1 text-xs text-gray-500">{{ __('expense-dimensions.no_children') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </details>
                    <div class="absolute right-2 top-2">
                        @include('filament.pages.expense-category-actions', ['record' => $category, 'parent' => null])
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
