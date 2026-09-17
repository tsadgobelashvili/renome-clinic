<x-filament::dropdown placement="bottom-end" width="xs" :shift="true" :teleport="true" class="shrink-0" wire:key="expense-menu-{{ $record->id }}">
    <x-slot name="trigger">
        <x-filament::icon-button icon="heroicon-m-ellipsis-horizontal" color="gray" size="sm" :label="__('filament-actions::group.trigger.label').' — '.$record->name" />
    </x-slot>
    <x-filament::dropdown.list x-on:click="close()">
        <x-filament::dropdown.list.item icon="heroicon-m-pencil-square" wire:click="edit({{ $record->id }}{{ $parent ? ', '.$parent : '' }})">
            {{ __('expense-categories.edit') }}
        </x-filament::dropdown.list.item>
        <x-filament::dropdown.list.item :icon="$record->active ? 'heroicon-m-pause-circle' : 'heroicon-m-check-circle'" wire:click="toggleActive({{ $record->id }}, {{ $parent ? 'true' : 'false' }})">
            {{ __('expense-categories.'.($record->active ? 'deactivate' : 'activate')) }}
        </x-filament::dropdown.list.item>
        <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" wire:confirm="{{ __('expense-categories.delete_help') }}" wire:click="deleteRecord({{ $record->id }}, {{ $parent ? 'true' : 'false' }})">
            {{ __('expense-categories.delete') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</x-filament::dropdown>
