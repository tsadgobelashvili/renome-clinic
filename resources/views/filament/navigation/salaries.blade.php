@php
    $isDoctorSalaryPage = $this instanceof \App\Filament\Pages\DoctorCompensation;
    $salaryMode = $isDoctorSalaryPage ? $this->staffTypeFilter : 'technicians';
@endphp

<nav class="mb-4 overflow-x-auto" aria-label="{{ __('salaries.title') }}" data-salary-navigation>
    <x-filament::tabs :label="__('salaries.title')">
        @foreach (\App\Filament\Support\SalaryNavigation::tabs() as $tab)
            @if ($isDoctorSalaryPage && $tab['mode'] !== 'technicians')
                <x-filament::tabs.item tag="button" type="button"
                    wire:key="salary-tab-{{ $tab['mode'] }}"
                    wire:click="$set('staffTypeFilter', '{{ $tab['mode'] }}')"
                    :active="$salaryMode === $tab['mode']" :data-salary-tab="$tab['mode']">
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @else
                <x-filament::tabs.item tag="a" :href="$tab['url']"
                    wire:key="salary-tab-{{ $tab['mode'] }}"
                    :active="$salaryMode === $tab['mode']" :data-salary-tab="$tab['mode']">
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endif
        @endforeach
    </x-filament::tabs>
</nav>
