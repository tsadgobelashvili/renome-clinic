<x-filament-panels::page>
    @php($this->prepareVisibleClassificationRows())
    @assets
        <script src="{{ asset('js/purchase-classification.js') }}?v={{ filemtime(public_path('js/purchase-classification.js')) }}"></script>
    @endassets
    <div
        x-data="purchaseClassificationState($wire)"
        x-on:beforeunload.window="if (dirtyCount()) { $event.preventDefault(); $event.returnValue = ''; }"
        x-on:livewire:navigate.document="if (dirtyCount() && !window.confirm(@js(__('catalog-classification.leave_warning')))) $event.preventDefault()"
    >
        {{ $this->table }}
        <div class="sticky bottom-0 z-10 mt-3 flex items-center justify-between gap-3 rounded-xl bg-white p-3 shadow ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
            <span class="text-sm text-gray-500" x-cloak x-show="dirtyCount()" aria-live="polite">{{ __('catalog-classification.pending') }}</span>
            <x-filament::button wire:click="saveClassifications" wire:loading.attr="disabled" wire:target="saveClassifications" x-bind:disabled="dirtyCount() === 0">
                {{ __('catalog-classification.save') }} (<span x-text="dirtyCount()">0</span>)
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
