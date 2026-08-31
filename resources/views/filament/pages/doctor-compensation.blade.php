<x-filament-panels::page>
    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900 md:grid-cols-5">
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
        <label class="space-y-1 text-sm">
            <span class="font-medium">პაციენტის ჯგუფი</span>
            <select wire:model="patientGroup" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                <option value="all">ყველა</option>
                <option value="clinic">Clinic</option>
                <option value="israel-partner">Israel Partner</option>
            </select>
        </label>
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღიდან</span><input type="date" wire:model="from" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღამდე</span><input type="date" wire:model="until" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">ექიმის პროცენტი</span><div class="flex items-center gap-2"><input type="number" min="0.01" max="100" step="0.01" wire:model="percentage" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"><span>%</span></div></label>
        <div class="flex gap-2 md:col-span-5"><x-filament::button wire:click="calculate" size="sm">დათვლა</x-filament::button></div>
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

        @if (! empty($report['owner_split_income']))
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-3 text-sm dark:border-primary-500/30 dark:bg-primary-500/10">
                <div class="mb-2 font-semibold">Owner Split მიღებული</div>
                @foreach ($report['owner_split_income'] as $share)
                    <div class="flex flex-wrap justify-between gap-2 border-t border-primary-100 py-1.5 text-xs first:border-0 dark:border-primary-500/20">
                        <span>{{ $share['visit_date'] }} · Visit #{{ $share['visit_id'] }} · {{ $share['patient'] }} · {{ $share['source_doctor'] }}</span>
                        <strong class="text-success-600">+{{ \App\Support\Currency::format($share['amount'], $share['currency']) }}</strong>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($report['details'] || ! empty($report['owner_split_income']))
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
        <section id="history" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="mb-3 font-semibold text-gray-950 dark:text-white">ხელფასების ისტორია</h2>

            <div class="space-y-3">
                @foreach ($settlements as $settlement)
                    @php
                        $lastIncluded = $settlement->last_included_item;
                        $visits = $settlement->items->groupBy('visit_id');
                    @endphp

                    <details class="group overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                        <summary class="cursor-pointer list-none px-3 py-3 marker:hidden">
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    @if (filled($settlement->patient_group_slug))
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            {{ $settlement->patient_group_slug === \App\Models\PatientGroup::ISRAEL_PARTNER_SLUG ? 'Israel Partner' : ($settlement->patient_group_slug === 'mixed' ? 'Mixed' : 'Clinic') }}
                                        </span>
                                    @endif
                                    <span class="text-xs text-gray-400 transition group-open:rotate-90">›</span>
                                    <span class="whitespace-nowrap text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ $settlement->period_start->format('d.m.Y') }} — {{ $settlement->period_end->format('d.m.Y') }}
                                    </span>
                                    <span class="rounded-full bg-success-50 px-2 py-0.5 text-[11px] font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                        დაფიქსირებული
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                                    <span class="whitespace-nowrap text-gray-500">სამუშაო <strong class="text-gray-700 dark:text-gray-200">{{ \App\Support\Currency::format((float) $settlement->performed_total, $settlement->currency) }}</strong></span>
                                    <span class="whitespace-nowrap text-success-600 dark:text-success-400">გადახდილი <strong>{{ \App\Support\Currency::format((float) $settlement->paid_amount, $settlement->currency) }}</strong></span>
                                    <span class="whitespace-nowrap text-warning-600 dark:text-warning-400">ხარჯი <strong>{{ \App\Support\Currency::format((float) $settlement->direct_expense_total, $settlement->currency) }}</strong></span>
                                    <span class="whitespace-nowrap text-gray-600 dark:text-gray-300">ექიმის ხელფასი <strong>{{ \App\Support\Currency::format((float) $settlement->normal_salary_total, $settlement->currency) }}</strong></span>
                                    @if ((float) $settlement->owner_split_received_total > 0)
                                        <span class="whitespace-nowrap text-success-600 dark:text-success-400">Owner Split +{{ \App\Support\Currency::format((float) $settlement->owner_split_received_total, $settlement->currency) }}</span>
                                    @endif
                                    <span class="whitespace-nowrap font-semibold text-primary-600 dark:text-primary-400">სულ დაფიქსირებული {{ \App\Support\Currency::format((float) $settlement->salary_total, $settlement->currency) }}</span>
                                </div>
                            </div>
                        </summary>

                        <div class="space-y-3 border-t border-gray-100 px-3 py-3 dark:border-white/10">
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                @foreach ([
                                    ['შესრულებული სამუშაო', $settlement->performed_total, null],
                                    ['გადახდილი', $settlement->paid_amount, 'text-success-600 dark:text-success-400'],
                                    ['ხარჯი', $settlement->direct_expense_total, 'text-warning-600 dark:text-warning-400'],
                                    ['საბაზო თანხა', $settlement->base_total, null],
                                    ['ექიმის ხელფასი', $settlement->normal_salary_total, null],
                                    ['Owner Split მიღებული', $settlement->owner_split_received_total, 'text-success-600 dark:text-success-400'],
                                    ['სულ დაფიქსირებული', $settlement->salary_total, 'text-primary-600 dark:text-primary-400'],
                                ] as [$label, $value, $color])
                                    <div class="rounded-md bg-gray-50 px-2.5 py-2 dark:bg-white/5">
                                        <div class="text-[11px] text-gray-500">{{ $label }}</div>
                                        <div @class(['mt-0.5 whitespace-nowrap text-sm font-semibold', $color ?: 'text-gray-950 dark:text-white'])>
                                            {{ \App\Support\Currency::format((float) $value, $settlement->currency) }}
                                        </div>
                                    </div>
                                @endforeach
                                <div class="rounded-md bg-gray-50 px-2.5 py-2 dark:bg-white/5">
                                    <div class="text-[11px] text-gray-500">პროცენტი</div>
                                    <div class="mt-0.5 whitespace-nowrap text-sm font-semibold text-gray-950 dark:text-white">{{ number_format((float) $settlement->percentage, 2) }}%</div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <div>
                                დაფიქსირდა: {{ $settlement->settled_at->format('d.m.Y H:i') }}
                                <span class="mx-1">·</span>
                                ბოლო ჩათვლილი პაციენტი: {{ $lastIncluded?->visit?->patient?->full_name ?? '—' }}
                                @if ($lastIncluded?->visit_id)
                                    <span class="mx-1">·</span> Visit #{{ $lastIncluded->visit_id }}
                                @endif
                                </div>
                                <div class="flex flex-wrap justify-end gap-1">
                                    @foreach ($settlement->historyRecords as $auditRecord)
                                        <x-filament::button
                                            type="button"
                                            size="xs"
                                            color="danger"
                                            wire:click="undoSettlement({{ $auditRecord->getKey() }})"
                                            wire:confirm="გაუქმდეს ეს ხელფასის დაფიქსირება? დაკავშირებული სამუშაო ან Owner Split წილი ხელახლა გახდება დასათვლელი."
                                        >გაუქმება #{{ $auditRecord->getKey() }}</x-filament::button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full min-w-[980px] text-xs">
                                    <thead class="bg-gray-50 text-[11px] font-medium text-gray-500 dark:bg-white/5">
                                        <tr>
                                            <th class="px-2.5 py-2 text-left">Visit ID</th>
                                            <th class="px-2.5 py-2 text-left">ვიზიტის თარიღი</th>
                                            <th class="px-2.5 py-2 text-left">პაციენტი</th>
                                            <th class="px-2.5 py-2 text-left">მანიპულაციები</th>
                                            <th class="px-2.5 py-2 text-right">შესრულებული</th>
                                            <th class="px-2.5 py-2 text-right">გადახდილი</th>
                                            <th class="px-2.5 py-2 text-right">ხარჯი</th>
                                            <th class="px-2.5 py-2 text-right">საბაზო</th>
                                            <th class="px-2.5 py-2 text-right">ექიმის წილი</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @foreach ($visits as $visitId => $items)
                                            @php
                                                $firstItem = $items->first();
                                                $sum = fn (string $snapshot, string $legacy): float => (float) $items->sum(
                                                    fn ($item): float => (float) ($item->{$snapshot} ?? $item->{$legacy})
                                                );
                                            @endphp
                                            <tr class="align-top">
                                                <td class="whitespace-nowrap px-2.5 py-2 font-medium">#{{ $visitId }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2">{{ $firstItem?->visit?->visit_date?->format('d.m.Y') ?? '—' }}</td>
                                                <td class="px-2.5 py-2">{{ $firstItem?->visit?->patient?->full_name ?? '—' }}</td>
                                                <td class="max-w-sm px-2.5 py-2">
                                                    <div class="space-y-0.5">
                                                        @foreach ($items as $item)
                                                            <div>
                                                                {{ $item->visitTreatmentCase?->display_name ?? '—' }}
                                                                <span class="whitespace-nowrap text-gray-500">×{{ max(1, (int) ($item->visitTreatmentCase?->quantity ?? 1)) }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </td>
                                                <td class="whitespace-nowrap px-2.5 py-2 text-right">{{ \App\Support\Currency::format($sum('total_value_snapshot', 'revenue'), $settlement->currency) }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-medium text-success-600 dark:text-success-400">{{ \App\Support\Currency::format($sum('paid_amount_snapshot', 'revenue'), $settlement->currency) }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2 text-right text-warning-600 dark:text-warning-400">{{ \App\Support\Currency::format($sum('expense_snapshot', 'direct_expense'), $settlement->currency) }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2 text-right">{{ \App\Support\Currency::format($sum('base_snapshot', 'salary_base'), $settlement->currency) }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2 text-right font-semibold text-primary-600 dark:text-primary-400">{{ \App\Support\Currency::format($sum('doctor_share_snapshot', 'doctor_share'), $settlement->currency) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @endif
</x-filament-panels::page>
