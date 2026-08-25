<x-filament-panels::page>
    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900 md:grid-cols-4">
        @if (! $doctorLocked)
            <label class="space-y-1 text-sm">
                <span class="font-medium">ექიმი</span>
                <select wire:model.live="doctorId" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="">აირჩიეთ ექიმი</option>
                    @foreach ($doctors as $doctor)<option value="{{ $doctor->getKey() }}">{{ $doctor->full_name }}</option>@endforeach
                </select>
                @error('doctorId') <div class="text-xs text-danger-600">{{ $message }}</div> @enderror
            </label>
        @else
            <div class="space-y-1 text-sm"><div class="font-medium">ექიმი</div><div>{{ $doctors->firstWhere('id', $doctorId)?->full_name }}</div></div>
        @endif
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღიდან</span><input type="date" wire:model="from" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღამდე</span><input type="date" wire:model="until" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">ექიმის პროცენტი</span><div class="flex items-center gap-2"><input type="number" min="0" max="100" step="0.01" wire:model="percentage" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"><span>%</span></div></label>
        <div class="flex gap-2 md:col-span-4"><x-filament::button wire:click="calculate" size="sm">დათვლა</x-filament::button></div>
    </div>

    @if ($report)
        @forelse ($report['totals'] as $currency => $totals)
            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ([['ვიზიტები', $totals['visits_count'], false], ['შესრულებული სამუშაო', $totals['work_total'], true], ['პირდაპირი ხარჯები', $totals['expense_total'], true], ['საბაზო თანხა', $totals['base_total'], true], ['ექიმის %', $report['percentage'].'%', false], ['ანაზღაურება', $totals['doctor_share'], true]] as $card)
                    <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900"><div class="text-xs text-gray-500">{{ $card[0] }}</div><div class="mt-1 text-lg font-semibold">{{ $card[2] ? \App\Support\Currency::format($card[1], $currency) : $card[1] }}</div></div>
                @endforeach
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-500">არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.</div>
        @endforelse

        @if ($report['details'])
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <table class="w-full min-w-[850px] text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr><th class="p-3 text-left">თარიღი</th><th class="p-3 text-left">პაციენტი</th><th class="p-3 text-left">მანიპულაციები</th><th class="p-3 text-right">სამუშაო</th><th class="p-3 text-right">ხარჯი</th><th class="p-3 text-right">ბაზა</th><th class="p-3 text-right">ექიმის წილი</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($report['details'] as $row)
                        <tr><td class="whitespace-nowrap p-3">{{ $row['visit_date'] }}</td><td class="p-3">{{ $row['patient'] }}</td><td class="p-3"><details><summary class="cursor-pointer">{{ collect($row['items'])->take(2)->pluck('name')->implode(', ') }}{{ count($row['items']) > 2 ? ' + '.(count($row['items']) - 2).' სხვა' : '' }}</summary><div class="mt-2 space-y-1 text-xs text-gray-500">@foreach ($row['items'] as $item)<div>{{ $item['name'] }} ×{{ $item['quantity'] }} — {{ \App\Support\Currency::format($item['revenue'], $row['currency']) }} / ხარჯი {{ \App\Support\Currency::format($item['direct_expense'], $row['currency']) }}</div>@endforeach</div></details></td><td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['work_total'], $row['currency']) }}</td><td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['expense_total'], $row['currency']) }}</td><td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['base_total'], $row['currency']) }}</td><td class="whitespace-nowrap p-3 text-right font-semibold">{{ \App\Support\Currency::format($row['doctor_share'], $row['currency']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-filament::button wire:click="confirmSettlement" wire:confirm="დაფიქსირდეს არჩეული სამუშაოების ხელფასი?" color="success" size="sm">ხელფასის დაფიქსირება</x-filament::button>
            @error('settlement') <div class="text-sm text-danger-600">{{ $message }}</div> @enderror
        @endif
    @endif

    @if ($settlements->isNotEmpty())
        <div id="history" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"><h2 class="mb-3 font-semibold">ხელფასების ისტორია</h2><div class="space-y-2">
            @foreach ($settlements as $settlement)
                @php($lastIncluded = $settlement->last_included_item)
                <details class="rounded-lg border border-gray-100 p-3 dark:border-white/10"><summary class="cursor-pointer text-sm"><span class="font-medium">{{ $settlement->period_start->format('d.m.Y') }} — {{ $settlement->period_end->format('d.m.Y') }}</span> · {{ \App\Support\Currency::format((float) $settlement->salary_total, $settlement->currency) }} · დაფიქსირებული</summary><div class="mt-2 text-xs text-gray-500">დაფიქსირდა: {{ $settlement->settled_at->format('d.m.Y H:i') }} · სამუშაო: {{ \App\Support\Currency::format((float) $settlement->performed_total, $settlement->currency) }} · ხარჯი: {{ \App\Support\Currency::format((float) $settlement->direct_expense_total, $settlement->currency) }} · {{ $settlement->percentage }}% · ბოლო პაციენტი: {{ $lastIncluded?->visit?->patient?->full_name ?? '—' }}</div><div class="mt-2 space-y-1 text-xs">@foreach ($settlement->items as $item)<div>Visit #{{ $item->visit_id }} · {{ $item->visit?->patient?->full_name ?? '—' }} · {{ $item->visitTreatmentCase?->display_name ?? '—' }} · სამუშაო {{ \App\Support\Currency::format((float) $item->revenue, $settlement->currency) }} · ხარჯი {{ \App\Support\Currency::format((float) $item->direct_expense, $settlement->currency) }} · ბაზა {{ \App\Support\Currency::format((float) $item->salary_base, $settlement->currency) }} · წილი {{ \App\Support\Currency::format((float) $item->doctor_share, $settlement->currency) }}</div>@endforeach</div></details>
            @endforeach
        </div></div>
    @endif
</x-filament-panels::page>
