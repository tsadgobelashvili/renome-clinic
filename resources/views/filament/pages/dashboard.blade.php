<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        <button
            type="button"
            wire:click="mountAction('cashboxOverview')"
            class="renome-dashboard-summary-card group min-h-28 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex h-full items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-sm font-medium leading-5 text-gray-600 dark:text-gray-300">სალარო</div>
                    <div class="mt-1.5 space-y-0.5">
                        <div class="whitespace-nowrap text-2xl font-semibold leading-tight tracking-tight text-gray-950 dark:text-white">
                            {{ \App\Support\Currency::format((float) ($cashBalances['GEL'] ?? 0), 'GEL') }}
                        </div>
                        <div class="whitespace-nowrap text-sm font-medium leading-5 text-gray-500 dark:text-gray-400">
                            {{ \App\Support\Currency::format((float) ($cashBalances['USD'] ?? 0), 'USD') }}
                        </div>
                    </div>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 transition group-hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-banknotes" class="size-5" />
                </span>
            </div>
        </button>

        <button
            type="button"
            wire:click="mountAction('manageTomography')"
            class="renome-dashboard-summary-card group min-h-28 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex h-full items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-sm font-medium leading-5 text-gray-600 dark:text-gray-300">ტომოგრაფია</div>
                    <div class="mt-1.5 whitespace-nowrap text-2xl font-semibold leading-tight tracking-tight text-gray-950 dark:text-white">დღეს: {{ $tomographyCount }}</div>
                    <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm font-medium leading-5 text-gray-500 dark:text-gray-400">
                        @forelse ($tomographyPayments as $currency => $amount)
                            <span class="whitespace-nowrap">მიღებული {{ \App\Support\Currency::format($amount, $currency) }}</span>
                        @empty
                            <span>მიღებული: 0.00 ₾</span>
                        @endforelse
                    </div>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 transition group-hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-camera" class="size-5" />
                </span>
            </div>
        </button>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
