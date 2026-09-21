@php
    $resource = \App\Filament\Resources\Purchases\PurchaseResource::class;
    $base = $resource::getRouteBaseName();
@endphp
<nav aria-label="RS / შესყიდვები">
    <x-filament::tabs label="RS / შესყიდვები">
        @foreach(['index' => 'დოკუმენტები', 'uncategorized' => 'უკატეგორიო', 'groups' => 'პროდუქციის ჯგუფები', 'analysis' => 'შესყიდვების ანალიზი'] as $page => $label)
            <x-filament::tabs.item tag="a" :href="$resource::getUrl($page)"
                :active="\Filament\Support\original_request()->routeIs($base.'.'.$page) || ($page === 'groups' && \Filament\Support\original_request()->routeIs($base.'.group-products')) || ($page === 'index' && \Filament\Support\original_request()->routeIs($base.'.edit', $base.'.create', $base.'.items'))">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
</nav>
