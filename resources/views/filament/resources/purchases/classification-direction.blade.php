@php($record = $getRecord())
<div class="min-w-40 space-y-1 px-3 py-2" wire:key="classification-direction-{{ $record->id }}">
    <x-filament::input.wrapper>
        <x-filament::input.select
            x-model="edits['{{ $record->id }}'].expense_direction_id"
            x-on:change="directionChanged('{{ $record->id }}')"
            aria-label="{{ $record->name }} · {{ __('expense-dimensions.direction') }}"
        >
            <option value="">{{ __('expense-entry.unclassified') }}</option>
            <template x-for="[id, direction] in Object.entries(directions)" :key="id">
                <option :value="id" x-text="direction.name"
                    :disabled="!direction.active && key(originals['{{ $record->id }}'].expense_direction_id) !== key(id)"
                    :selected="key(edits['{{ $record->id }}'].expense_direction_id) === key(id)"></option>
            </template>
        </x-filament::input.select>
    </x-filament::input.wrapper>
    <x-filament::badge color="warning" x-cloak x-show="isDirty('{{ $record->id }}')">{{ __('catalog-classification.dirty') }}</x-filament::badge>
    @error('pendingClassifications.'.$record->id.'.expense_direction_id')
        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
    @enderror
</div>
