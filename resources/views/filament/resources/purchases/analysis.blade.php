<x-filament-panels::page>
    {{ $this->form }}
    @if($selectedGroup !== null)
        <x-filament::button size="sm" color="gray" wire:click="back" icon="heroicon-o-arrow-left">უკან</x-filament::button>
    @endif
    <p class="text-xs text-gray-500">RS დოკუმენტების პროდუქტები · GEL · საშუალო ფასი = თანხა / რაოდენობა. შერეული ერთეულების რაოდენობები პირდაპირ შესადარებელი არ არის.</p>
    {{ $this->table }}
</x-filament-panels::page>
