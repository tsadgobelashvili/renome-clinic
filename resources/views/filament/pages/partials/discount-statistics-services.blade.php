@foreach ($expandedServices ?? [] as $service)
    <div class="renome-discount-row bg-gray-50/50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
        <button type="button" class="pl-6" wire:click='openDetails(@json($expandedScope + ["service_name" => $service->service_name]))'>
            <span class="block text-[10px] text-gray-500">{{ \App\Services\FullDiscountStatistics::groupLabel($service) }}</span>
            {{ $service->service_name ?: __('discount-statistics.no_items') }}
        </button>
        @include('filament.pages.partials.discount-statistics-metrics', ['row' => $service])
    </div>
@endforeach
