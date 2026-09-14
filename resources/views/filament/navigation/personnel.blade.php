<nav aria-label="{{ __('personnel.title') }}" data-personnel-navigation>
    <x-filament::tabs :label="__('personnel.title')">
        @foreach(\App\Filament\Support\PersonnelNavigation::tabs() as $tab)
            <x-filament::tabs.item tag="a" :href="$tab['url']" :active="in_array($tab['resource'], $scopes, true)">
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
</nav>
