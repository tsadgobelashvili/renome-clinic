@php($record = $getRecord())
<div class="min-w-44 space-y-1 px-3 py-2" wire:key="classification-group-{{ $record->id }}">
    <div class="flex items-center gap-2">
        <x-filament::input.wrapper>
            <x-filament::input.select
                x-model="edits['{{ $record->id }}'].purchase_product_group_id"
                x-bind:disabled="!key(edits['{{ $record->id }}'].expense_direction_id)"
                aria-label="{{ $record->name }} · {{ __('expense-entry.product_group') }}"
            >
                <option value="">{{ __('expense-entry.unclassified') }}</option>
                <template x-if="legacyGroup('{{ $record->id }}')">
                    <option :value="edits['{{ $record->id }}'].purchase_product_group_id" selected disabled x-text="legacyGroup('{{ $record->id }}')"></option>
                </template>
                <template x-for="group in groupOptions('{{ $record->id }}')" :key="group.id">
                    <option :value="group.id" x-text="group.name" :selected="key(edits['{{ $record->id }}'].purchase_product_group_id) === group.id"></option>
                </template>
            </x-filament::input.select>
        </x-filament::input.wrapper>
        @can('create', \App\Models\PurchaseProductGroup::class)
            @can('update', $record)
                <x-filament::icon-button icon="heroicon-m-plus" :label="__('product-groups.create')"
                    x-cloak x-show="key(edits['{{ $record->id }}'].expense_direction_id)"
                    wire:click="mountTableAction('createGroup', '{{ $record->id }}')" />
            @endcan
        @endcan
    </div>
    @error('pendingClassifications.'.$record->id.'.purchase_product_group_id')
        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
    @enderror
</div>
