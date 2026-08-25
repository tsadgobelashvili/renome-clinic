<div class="space-y-4">
    @if ($lastSettled['last_settled_at'] ?? null)
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-200">
            ბოლო დაფიქსირებული ხელფასი: {{ $lastSettled['last_settled_at']->format('d.m.Y H:i') }}
            — ჩათვლილი იყო: {{ $lastSettled['last_patient'] ?? '—' }}
            @if (filled($lastSettled['last_visit_id'] ?? null))
                (Visit #{{ $lastSettled['last_visit_id'] }})
            @endif
        </div>
    @endif

    @forelse ($report['totals'] as $currency => $totals)
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['შესრულებული სამუშაო', $totals['work_total']],
                ['პირდაპირი ხარჯები', $totals['expense_total']],
                ['საბაზო თანხა', $totals['base_total']],
                ['ექიმის %', $report['percentage'], true],
                ['საბოლოო ხელფასი', $totals['doctor_share']],
            ] as $summary)
                <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                    <div class="text-[11px] text-gray-500">{{ $summary[0] }}</div>
                    <div class="mt-0.5 whitespace-nowrap text-sm font-semibold text-gray-950 dark:text-white">
                        {{ ($summary[2] ?? false) ? number_format((float) $summary[1], 2).'%' : \App\Support\Currency::format((float) $summary[1], $currency) }}
                    </div>
                </div>
            @endforeach
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 px-3 py-4 text-sm text-gray-500 dark:border-white/10">
            არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.
        </div>
    @endforelse

    @if ($report['details'])
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[880px] text-xs">
                <thead class="bg-gray-50 text-[11px] font-medium text-gray-500 dark:bg-white/5">
                    <tr>
                        <th class="px-2.5 py-2 text-left">თარიღი</th>
                        <th class="px-2.5 py-2 text-left">პაციენტი</th>
                        <th class="px-2.5 py-2 text-left">მანიპულაციები</th>
                        <th class="px-2.5 py-2 text-right">შესრულებული</th>
                        <th class="px-2.5 py-2 text-right">ხარჯი</th>
                        <th class="px-2.5 py-2 text-right">საბაზო</th>
                        <th class="px-2.5 py-2 text-right">ექიმის წილი</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($report['details'] as $row)
                        <tr
                            @class([
                                'align-top',
                                'bg-success-50 ring-1 ring-inset ring-success-300 dark:bg-success-500/10 dark:ring-success-500/40' => (int) $row['visit_id'] === (int) $cutoffVisitId,
                            ])
                            wire:key="salary-visit-{{ $row['visit_id'] }}"
                        >
                            <td class="whitespace-nowrap px-2.5 py-2">
                                <div>{{ $row['visit_date'] }}</div>
                                <div class="text-[10px] text-gray-500">Visit #{{ $row['visit_id'] }}</div>
                            </td>
                            <td class="px-2.5 py-2 font-medium text-gray-950 dark:text-white">{{ $row['patient'] }}</td>
                            <td class="max-w-xs px-2.5 py-2">
                                <div x-data="{ expanded: false }" class="space-y-0.5">
                                    @foreach ($row['items'] as $index => $item)
                                        <div x-show="expanded || {{ $index }} < 2" @if ($index >= 2) x-cloak @endif>
                                            {{ $item['name'] }} <span class="text-gray-500">×{{ $item['quantity'] }}</span>
                                        </div>
                                    @endforeach
                                    @if (count($row['items']) > 2)
                                        <button type="button" class="text-[11px] font-medium text-primary-600"
                                            x-on:click="expanded = ! expanded"
                                            x-text="expanded ? 'დაკეცვა ⌃' : '+ {{ count($row['items']) - 2 }} სხვა ⌄'">
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2.5 py-2 text-right">{{ \App\Support\Currency::format($row['work_total'], $row['currency']) }}</td>
                            <td class="px-2.5 py-2 text-right" wire:key="salary-expenses-{{ $row['visit_id'] }}">
                                <div class="min-w-48 space-y-1">
                                    <div class="whitespace-nowrap font-medium">{{ \App\Support\Currency::format($row['expense_total'], $row['currency']) }}</div>
                                    <details class="text-left">
                                        <summary class="cursor-pointer text-[11px] font-medium text-primary-600">ხარჯების რედაქტირება</summary>
                                        <div class="mt-1.5 space-y-2">
                                            @foreach ($row['items'] as $item)
                                                <div class="space-y-1 rounded-md border border-gray-100 p-1.5 dark:border-white/10">
                                                    <div class="truncate text-[10px] text-gray-500" title="{{ $item['name'] }}">{{ $item['name'] }}</div>
                                                    @foreach ($item['expenses'] as $expense)
                                                        <div class="grid grid-cols-[minmax(0,1fr)_6.5rem_1.5rem] gap-1"
                                                            x-data="{
                                                                name: @js($expense['name']),
                                                                amount: @js(number_format($expense['amount'], 2, '.', '')),
                                                                savedName: @js($expense['name']),
                                                                savedAmount: @js(number_format($expense['amount'], 2, '.', '')),
                                                                save() {
                                                                    $wire.saveSalaryExpense({{ $item['id'] }}, {{ $expense['id'] }}, this.name, this.amount)
                                                                        .then((saved) => {
                                                                            if (saved) {
                                                                                this.savedName = this.name
                                                                                this.savedAmount = this.amount
                                                                            } else {
                                                                                this.name = this.savedName
                                                                                this.amount = this.savedAmount
                                                                            }
                                                                        })
                                                                },
                                                            }">
                                                            <input type="text" x-model="name" placeholder="წყარო"
                                                                class="min-w-0 rounded-md border-gray-300 px-1.5 py-1 text-[11px] dark:border-white/10 dark:bg-gray-900"
                                                                x-on:keydown.enter.prevent="save()" x-on:change="save()" />
                                                            <div class="flex min-w-0 items-center overflow-hidden rounded-md border border-gray-300 dark:border-white/10">
                                                                <input type="number" min="0.01" step="0.01" x-model="amount"
                                                                    class="min-w-0 flex-1 border-0 bg-transparent px-1.5 py-1 text-right text-[11px] focus:ring-0"
                                                                    x-on:keydown.enter.prevent="save()" x-on:change="save()" />
                                                                <span class="border-s border-gray-200 px-1 text-[10px] text-gray-500 dark:border-white/10">{{ \App\Support\Currency::symbol($row['currency']) }}</span>
                                                            </div>
                                                            <button type="button" title="წაშლა" class="text-danger-600"
                                                                wire:click="deleteSalaryExpense({{ $item['id'] }}, {{ $expense['id'] }})">×</button>
                                                        </div>
                                                    @endforeach

                                                    <div x-data="{ open: false, name: '', amount: '' }">
                                                        <button x-show="! open" type="button" class="text-[10px] font-medium text-primary-600"
                                                            x-on:click="open = true">+ ხარჯი</button>
                                                        <div x-show="open" x-cloak class="grid grid-cols-[minmax(0,1fr)_6.5rem_3rem] gap-1">
                                                            <input type="text" x-model="name" placeholder="წყარო"
                                                                class="min-w-0 rounded-md border-gray-300 px-1.5 py-1 text-[11px] dark:border-white/10 dark:bg-gray-900" />
                                                            <input type="number" min="0.01" step="0.01" x-model="amount" placeholder="0.00"
                                                                class="min-w-0 rounded-md border-gray-300 px-1.5 py-1 text-right text-[11px] dark:border-white/10 dark:bg-gray-900" />
                                                            <div class="flex">
                                                                <button type="button" title="შენახვა" class="size-6 text-primary-600"
                                                                    x-on:click="$wire.saveSalaryExpense({{ $item['id'] }}, null, name, amount).then((saved) => { if (saved) { open = false; name = ''; amount = '' } })">✓</button>
                                                                <button type="button" title="გაუქმება" class="size-6 text-gray-500" x-on:click="open = false">×</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-2.5 py-2 text-right">{{ \App\Support\Currency::format($row['base_total'], $row['currency']) }}</td>
                            <td class="whitespace-nowrap px-2.5 py-2 text-right font-semibold">{{ \App\Support\Currency::format($row['doctor_share'], $row['currency']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
