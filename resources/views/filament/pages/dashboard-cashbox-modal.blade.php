<div class="space-y-4">
    @php
        $money = fn (array $values) => collect(['GEL', 'USD'])->map(
            fn (string $currency) => \App\Support\Currency::format((float) ($values[$currency] ?? 0), $currency)
        );

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

        @if (\App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource::canCreate() && $day->status !== 'closed')
            <x-filament::button
                tag="a"
                :href="\App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource::getUrl('create', ['entry' => 'cashbox', 'cashbox_day' => $day->id])"
                size="sm"
                color="gray"
                icon="heroicon-o-banknotes"
                class="renome-cashbox-quick-action renome-cashbox-quick-action--advance"
            >თანამშრომლის ავანსი</x-filament::button>
        @endif
    </div>

    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['საწყისი ნაშთი', $summary['opening']],
            ['ნაღდი შემოსავალი', $summary['cashIncomeByCurrency']],
            ['ნაღდი გასავალი', [
                'GEL' => ($summary['cashExpensesByCurrency']['GEL'] ?? 0) + ($summary['withdrawalsByCurrency']['GEL'] ?? 0),
                'USD' => ($summary['cashExpensesByCurrency']['USD'] ?? 0) + ($summary['withdrawalsByCurrency']['USD'] ?? 0),
            ]],
            ['მიმდინარე ნაღდი', $summary['expectedByCurrency']],
            ['ბარათით შემოსავალი', $summary['cardIncomeByCurrency']],
        ] as [$label, $values])
            <div @class(['rounded-lg border p-3', 'border-indigo-200 bg-indigo-50 dark:border-indigo-500/30 dark:bg-indigo-500/10' => $loop->last, 'border-gray-200 dark:border-white/10' => ! $loop->last])>
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
                <tr><th class="p-2">დრო</th><th class="p-2">ტიპი</th><th class="p-2">კატეგორია</th><th class="p-2">აღწერა</th><th class="p-2">მეთოდი</th><th class="p-2 text-right">თანხა</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($transactions as $transaction)
                    @include('filament.pages.partials.cashbox-movement-row', [
                        'transaction' => $transaction,
                        'amountDisplay' => \App\Support\Currency::format($transaction->amount, $transaction->currency),
                    ])
                @empty
                    <tr><td colspan="6" class="p-5 text-center text-gray-500">დღეს მოძრაობა არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex justify-end">
        <x-filament::button :href="$historyUrl" tag="a" color="gray" size="sm">სალაროს ისტორია</x-filament::button>
    </div>
</div>
