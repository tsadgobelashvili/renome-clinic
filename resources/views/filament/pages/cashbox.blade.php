<x-filament-panels::page>
    @if ($unresolvedPreviousDay)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
            <strong>წინა დღე არ არის დახურული:</strong> {{ $unresolvedPreviousDay->date->format('d.m.Y') }}.
            ფინანსური სიზუსტისთვის ახალი ხარჯი/ამოღება დაბლოკილია, სანამ წინა დღეს არ დახურავთ.
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach ([
            ['საწყისი ნაშთი', $day->opening_balance],
            ['ნაღდი შემოსავალი', $summary['cashIncome']],
            ['ნაღდი ხარჯი', $summary['cashExpenses']],
            ['ამოღებული ქეში', $summary['withdrawals']],
            ['მიმდინარე ნაღდი', $summary['expected']],
            ['ბარათით შემოსავალი', $summary['cardIncome']],
        ] as [$label, $value])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-semibold leading-tight text-gray-950 dark:text-white">{{ \App\Support\Currency::format($value, 'GEL') }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between text-sm">
        <span>{{ $day->date->format('d.m.Y') }}</span>
        <x-filament::badge :color="$day->status === 'open' ? 'success' : 'gray'">{{ $day->status === 'open' ? 'ღია' : 'დახურული' }}</x-filament::badge>
    </div>

    {{ $this->table }}

    <section class="space-y-3">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">ბოლო დღეები</h2>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[70rem] text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5"><tr>
                    <th class="p-3">თარიღი</th><th class="p-3">საწყისი</th><th class="p-3">Cash შემოსავალი</th><th class="p-3">Card შემოსავალი</th>
                    <th class="p-3">Cash ხარჯი</th><th class="p-3">Card ხარჯი</th><th class="p-3">ამოღება</th><th class="p-3">Expected</th>
                    <th class="p-3">Actual</th><th class="p-3">სხვაობა</th><th class="p-3">Carry</th><th class="p-3">სტატუსი</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($history as $row)
                    <tr>
                        <td class="p-3">{{ $row['day']->date->format('d.m.y') }}</td>
                        @foreach ([$row['day']->opening_balance, $row['summary']['cashIncome'], $row['summary']['cardIncome'], $row['summary']['cashExpenses'], $row['summary']['cardExpenses'], $row['summary']['withdrawals'], $row['day']->status === 'closed' ? $row['day']->expected_closing_balance : $row['summary']['expected'], $row['day']->actual_closing_balance, $row['summary']['difference'], $row['day']->carry_forward_balance] as $amount)
                            <td class="p-3 whitespace-nowrap">{{ $amount === null ? '—' : \App\Support\Currency::format($amount, 'GEL') }}</td>
                        @endforeach
                        <td class="p-3">{{ $row['day']->status === 'open' ? 'ღია' : 'დახურული' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
