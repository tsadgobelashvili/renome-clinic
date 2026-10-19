<x-filament-panels::page>
    <div class="space-y-3" data-purchase-analysis-compact>
    <nav aria-label="ანალიზის დონე" class="flex flex-wrap items-center gap-2 text-sm text-gray-500">
        @if($selectedDirection !== null)
            <x-filament::button size="xs" color="gray" wire:click="back" icon="heroicon-o-arrow-left" class="w-auto shrink-0">უკან</x-filament::button>
        @endif
        <span>შესყიდვების ანალიზი</span>
        @foreach($trail as $label)
            <span aria-hidden="true">/</span><span>{{ $label }}</span>
        @endforeach
    </nav>
    {{ $this->form }}
    <p class="text-xs text-gray-500">RS · GEL · რაოდენობა, თანხა და შესყიდვების რიცხვი — არჩეული პერიოდით. პროდუქტის ბოლო შესყიდვა და ინტერვალი — სრული ვალიდური ისტორიით. შერეული ერთეულების რაოდენობა არ ჯამდება.</p>
    {{ $this->table }}
    </div>
</x-filament-panels::page>
