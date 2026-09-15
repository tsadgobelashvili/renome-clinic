<x-filament-panels::page>
    @if ($unresolvedPreviousDay)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
            <strong>წინა დღე არ არის დახურული:</strong> {{ $unresolvedPreviousDay->date->format('d.m.Y') }}.
            ფინანსური სიზუსტისთვის ახალი ხარჯი/ამოღება დაბლოკილია, სანამ წინა დღეს არ დახურავთ.
            <a
                href="{{ \App\Filament\Pages\Cashbox::getUrl(['date' => $unresolvedPreviousDay->date->toDateString()]) }}"
                class="ml-2 whitespace-nowrap font-semibold underline underline-offset-2"
            >დღის გახსნა</a>
        </div>
    @endif

    @php
        $moneyLines = fn (array $amounts, bool $showZeroUsd = false) => collect(['GEL', 'USD'])
            ->filter(fn (string $currency) => $currency === 'GEL' || $showZeroUsd || (float) ($amounts[$currency] ?? 0) !== 0.0)
            ->map(fn (string $currency) => array_key_exists($currency, $amounts) && $amounts[$currency] === null
                ? '—'
                : \App\Support\Currency::format((float) ($amounts[$currency] ?? 0), $currency));
    @endphp

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @foreach ([
            ['საწყისი ნაშთი', $summary['opening']],
            ['ნაღდი შემოსავალი', $summary['cashIncomeByCurrency']],
            ['ბარათით შემოსავალი', $summary['cardIncomeByCurrency']],
            ['ნაღდი ხარჯი', $summary['cashExpensesByCurrency']],
            ['ამოღებული ქეში', $summary['withdrawalsByCurrency']],
            ['მიმდინარე ნაღდი', $summary['expectedByCurrency']],
        ] as [$label, $values])
            <div @class(['rounded-xl border p-4 shadow-sm', 'border-indigo-200 bg-indigo-50 dark:border-indigo-500/30 dark:bg-indigo-500/10' => $loop->index === 2, 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $loop->index !== 2])>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 space-y-0.5 text-lg font-semibold leading-tight text-gray-950 dark:text-white">
                    @foreach ($moneyLines($values) as $line)<div class="whitespace-nowrap">{{ $line }}</div>@endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between text-sm">
        <span>{{ $day->date->format('d.m.Y') }}</span>
        <x-filament::badge :color="$day->status === 'open' ? 'success' : 'gray'">{{ $day->status === 'open' ? 'ღია' : 'დახურული' }}</x-filament::badge>
    </div>

    {{ $this->table }}

    <section id="history" class="space-y-3">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">სალაროს ისტორია</h2>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[78rem] text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5"><tr>
                    <th class="p-3">თარიღი</th><th class="p-3">საწყისი</th><th class="p-3">ნაღდი შემოსავალი</th>
                    <th class="p-3 text-indigo-700 dark:text-indigo-300">ბარათით შემოსავალი</th>
                    <th class="p-3">ნაღდი გასავალი</th><th class="p-3">Carry</th><th class="p-3">საბოლოო</th>
                    <th class="p-3">დახურა</th><th class="p-3">სტატუსი</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($history as $row)
                    <tr>
                        <td class="p-3">{{ $row['day']->date->format('d.m.y') }}</td>
                        @foreach ([
                            $row['summary']['opening'],
                            $row['summary']['cashIncomeByCurrency'],
                            $row['summary']['cardIncomeByCurrency'],
                            [
                                'GEL' => ($row['summary']['cashExpensesByCurrency']['GEL'] ?? 0) + ($row['summary']['withdrawalsByCurrency']['GEL'] ?? 0),
                                'USD' => ($row['summary']['cashExpensesByCurrency']['USD'] ?? 0) + ($row['summary']['withdrawalsByCurrency']['USD'] ?? 0),
                            ],
                            ['GEL' => $row['day']->carry_forward_balance, 'USD' => $row['day']->carry_forward_balance_usd],
                            ['GEL' => $row['day']->actual_closing_balance, 'USD' => $row['day']->actual_closing_balance_usd],
                        ] as $amounts)
                            <td class="p-3 whitespace-nowrap">
                                @foreach ($moneyLines($amounts) as $line)<div>{{ $line }}</div>@endforeach
                            </td>
                        @endforeach
                        <td class="p-3 text-xs">
                            <div>{{ $row['day']->closer?->name ?? '—' }}</div>
                            <div class="text-gray-500">{{ $row['day']->closed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</div>
                        </td>
                        <td class="p-3">{{ $row['day']->status === 'open' ? 'ღია' : 'დახურული' }}</td>
                    </tr>
                    @if ($row['day']->status === 'closed')
                        <tr>
                            <td colspan="9" class="bg-gray-50/60 p-2 dark:bg-white/[0.02]">
                                <details>
                                    <summary class="cursor-pointer text-xs font-medium text-primary-600">დახურული დღის დეტალები</summary>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                                        @foreach ([
                                            ['ნაღდი შემოსავალი', $row['summary']['cashIncomeByCurrency']],
                                            ['ბარათით შემოსავალი', $row['summary']['cardIncomeByCurrency']],
                                            ['ხარჯები', $row['summary']['expensesByCurrency']],
                                            ['პროდუქტების გაყიდვა', $row['summary']['productSalesByCurrency']],
                                            ['საწყისი ნაშთი / Carry', $row['summary']['opening']],
                                            ['ქეშის ამოღება', $row['summary']['withdrawalsByCurrency']],
                                            ['დახურვის ნაშთი', ['GEL' => $row['day']->actual_closing_balance, 'USD' => $row['day']->actual_closing_balance_usd]],
                                        ] as [$label, $amounts])
                                            <div class="rounded-lg border border-gray-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-900">
                                                <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ $label }}</div>
                                                <div class="mt-1 font-semibold text-gray-950 dark:text-white">
                                                    @foreach ($moneyLines($amounts) as $line)<div class="whitespace-nowrap">{{ $line }}</div>@endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                        <table class="w-full min-w-[42rem] text-xs">
                                            <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/5"><tr><th class="p-2">დრო</th><th class="p-2">ტიპი</th><th class="p-2">კატეგორია</th><th class="p-2">აღწერა</th><th class="p-2">მეთოდი</th><th class="p-2 text-right">თანხა</th></tr></thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                                @foreach ($row['transactions'] as $historyTransaction)
                                                    @include('filament.pages.partials.cashbox-movement-row', [
                                                        'transaction' => $historyTransaction['transaction'],
                                                        'amountDisplay' => $historyTransaction['amount_display'],
                                                    ])
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
