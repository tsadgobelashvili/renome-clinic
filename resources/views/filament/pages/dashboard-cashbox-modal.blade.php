<div class="space-y-4">
    @php
        $money = fn (array $values) => collect(['GEL', 'USD'])->map(
            fn (string $currency) => \App\Support\Currency::format((float) ($values[$currency] ?? 0), $currency)
        );
        $serviceNames = function ($transaction) {
            if ($transaction->type === 'product_sale') {
                return $transaction->productSale?->items->map(
                    fn ($item) => ($item->product?->name ?? 'პროდუქტი').' ×'.($item->quantity ?: 1)
                )->values() ?? collect();
            }

            $items = $transaction->visit?->treatmentCaseItems->map(
                fn ($item) => $item->treatmentCase?->name ?? $item->custom_service_name
            )->filter()->values() ?? collect();

            if ($items->isEmpty() && $transaction->visit?->visit_type === 'consultation') {
                return collect(['კონსულტაცია']);
            }

            return $items;
        };
    @endphp

    <div class="renome-cashbox-quick-actions">
        <x-filament::button
            type="button"
            size="sm"
            color="gray"
            icon="heroicon-o-wallet"
            class="renome-cashbox-quick-action renome-cashbox-quick-action--cash"
            wire:click="mountAction('dashboardOpeningBalance')"
        >ქეშის დამატება</x-filament::button>

        <x-filament::button
            type="button"
            size="sm"
            color="gray"
            icon="heroicon-o-minus-circle"
            class="renome-cashbox-quick-action renome-cashbox-quick-action--expense"
            wire:click="mountAction('dashboardExpense')"
        >ხარჯი</x-filament::button>

        <x-filament::button
            type="button"
            size="sm"
            color="gray"
            icon="heroicon-o-shopping-bag"
            class="renome-cashbox-quick-action renome-cashbox-quick-action--product"
            wire:click="mountAction('dashboardProductSale')"
        >პროდუქტის გაყიდვა</x-filament::button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['საწყისი ნაშთი', $summary['opening']],
            ['ნაღდი შემოსავალი', $summary['cashIncomeByCurrency']],
            ['ნაღდი გასავალი', [
                'GEL' => ($summary['cashExpensesByCurrency']['GEL'] ?? 0) + ($summary['withdrawalsByCurrency']['GEL'] ?? 0),
                'USD' => ($summary['cashExpensesByCurrency']['USD'] ?? 0) + ($summary['withdrawalsByCurrency']['USD'] ?? 0),
            ]],
            ['მიმდინარე ნაღდი', $summary['expectedByCurrency']],
        ] as [$label, $values])
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-sm font-semibold">
                    @foreach ($money($values) as $line)<div class="whitespace-nowrap">{{ $line }}</div>@endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="max-h-80 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs text-gray-500 dark:bg-gray-900">
                <tr><th class="p-2">დრო</th><th class="p-2">პაციენტი</th><th class="p-2">სერვისი</th><th class="p-2">ექიმი</th><th class="p-2 text-right">თანხა</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($transactions as $transaction)
                    @php
                        $services = $serviceNames($transaction);
                        $serviceSummary = $services->implode(', ');
                        $showDetails = $services->count() > 2 || mb_strlen($serviceSummary) > 48;
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap p-2">{{ $transaction->transaction_date->timezone(config('app.timezone'))->format('H:i') }}</td>
                        <td class="p-2 font-medium text-gray-950 dark:text-white">
                            {{ $transaction->patient?->full_name ?? $transaction->productSale?->patient?->full_name ?? '—' }}
                        </td>
                        <td class="max-w-64 p-2">
                            <div class="truncate" title="{{ $serviceSummary }}">
                                {{ $showDetails ? $services->take(2)->implode(', ').($services->count() > 2 ? ' · +'.($services->count() - 2) : '…') : ($serviceSummary ?: '—') }}
                            </div>
                            @if ($showDetails)
                                <button
                                    type="button"
                                    class="mt-0.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    wire:click="mountAction('cashboxPaymentDetails', { transaction: {{ $transaction->getKey() }} })"
                                >დეტალების ნახვა</button>
                            @endif
                        </td>
                        <td class="p-2">{{ $transaction->visit?->doctor?->full_name ?? '—' }}</td>
                        <td class="whitespace-nowrap p-2 text-right font-medium">
                            {{ in_array($transaction->type, ['expense', 'cash_withdrawal', 'cash_transfer_out'], true) ? '−' : '+' }}{{ \App\Support\Currency::format($transaction->amount, $transaction->currency) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-5 text-center text-gray-500">დღეს ნაღდი მოძრაობა არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex justify-end">
        <x-filament::button :href="$historyUrl" tag="a" color="gray" size="sm">სალაროს ისტორია</x-filament::button>
    </div>
</div>
