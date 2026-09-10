<x-filament-panels::page>
    <div class="renome-dashboard-summary-grid grid gap-4 md:grid-cols-2">
        <button
            type="button"
            wire:click="mountAction('cashboxOverview')"
            class="renome-dashboard-summary-card group rounded-lg border border-gray-200 bg-white p-4 text-left shadow-none transition hover:border-primary-400 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex h-full items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[13px] font-medium leading-4 text-gray-500 dark:text-gray-400">სალარო</div>
                    <div class="mt-1 space-y-0.5">
                        <div class="text-xs text-gray-500">{{ app()->getLocale() === 'en' ? 'Cash' : 'ნაღდი' }}</div>
                        <div class="whitespace-nowrap text-2xl font-medium leading-7 tracking-tight text-gray-950 dark:text-white">
                            {{ \App\Support\Currency::format((float) ($cashBalances['GEL'] ?? 0), 'GEL') }}
                        </div>
                        <div class="whitespace-nowrap text-xs font-normal leading-4 text-gray-500 dark:text-gray-400">
                            {{ \App\Support\Currency::format((float) ($cashBalances['USD'] ?? 0), 'USD') }}
                        </div>
                    </div>
                </div>
                <div class="rounded-lg bg-indigo-50 px-3 py-2 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-200" data-cashier-card-total>
                    <div class="flex items-center gap-1 text-xs"><x-filament::icon icon="heroicon-o-credit-card" class="size-4" />{{ app()->getLocale() === 'en' ? 'Card · today' : 'ბარათი · დღეს' }}</div>
                    <div class="mt-1 whitespace-nowrap text-base font-semibold tabular-nums">{{ \App\Support\Currency::format($cardReceipts['GEL'] ?? 0, 'GEL') }}</div>
                    @if(($cardReceipts['USD'] ?? 0) > 0)<div class="text-xs tabular-nums">{{ \App\Support\Currency::format($cardReceipts['USD'], 'USD') }}</div>@endif
                </div>
                <span class="renome-dashboard-summary-icon flex size-8 shrink-0 items-center justify-center rounded-md bg-gray-50 text-primary-600 transition group-hover:bg-primary-50 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-banknotes" class="size-4" />
                </span>
            </div>
        </button>

        <button
            type="button"
            wire:click="mountAction('manageTomography')"
            class="renome-dashboard-summary-card group rounded-lg border border-gray-200 bg-white p-4 text-left shadow-none transition hover:border-primary-400 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex h-full items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[13px] font-medium leading-4 text-gray-500 dark:text-gray-400">ტომოგრაფია</div>
                    <div class="mt-1 whitespace-nowrap text-2xl font-medium leading-7 tracking-tight text-gray-950 dark:text-white">დღეს: {{ $tomographyCount }}</div>
                    <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs font-normal leading-4 text-gray-500 dark:text-gray-400">
                        @if ($tomographyPayments->isNotEmpty())
                            <span class="whitespace-nowrap">მიღებული: {{ $tomographyPayments->map(fn ($amount, $currency) => \App\Support\Currency::format($amount, $currency))->join(' + ') }}</span>
                        @else
                            <span>მიღებული: 0.00 ₾</span>
                        @endif
                    </div>
                </div>
                <span class="renome-dashboard-summary-icon flex size-8 shrink-0 items-center justify-center rounded-md bg-gray-50 text-primary-600 transition group-hover:bg-primary-50 dark:bg-primary-500/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-camera" class="size-4" />
                </span>
            </div>
        </button>
    </div>

    <div class="renome-dashboard-visits">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
