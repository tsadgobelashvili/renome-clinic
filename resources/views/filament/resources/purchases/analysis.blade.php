<x-filament-panels::page>
    {{ $this->form }}
    <nav aria-label="ანალიზის დონე" class="flex flex-wrap items-center gap-2 text-sm text-gray-500">
        <span>მიმართულებები</span>
        @foreach($trail as $label)
            <span aria-hidden="true">/</span><span>{{ $label }}</span>
        @endforeach
    </nav>
    @if($selectedDirection !== null)
        <x-filament::button size="sm" color="gray" wire:click="back" icon="heroicon-o-arrow-left">უკან</x-filament::button>
    @endif
    <p class="text-xs text-gray-500">RS დოკუმენტების პროდუქტები · GEL · საშუალო ფასი = თანხა / რაოდენობა. შერეული ერთეულების რაოდენობები პირდაპირ შესადარებელი არ არის.</p>
    {{ $this->table }}
</x-filament-panels::page>
