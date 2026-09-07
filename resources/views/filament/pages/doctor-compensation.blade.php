<x-filament-panels::page>
    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900 md:grid-cols-7">
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
            <select wire:model.live="patientGroup" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                <option value="clinic">Clinic</option>
                <option value="israel-partner">Israel Partner</option>
            </select>
        </label>
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღიდან</span><input type="date" wire:model="from" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">თარიღამდე</span><input type="date" wire:model="until" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"></label>
        <label class="space-y-1 text-sm"><span class="font-medium">ექიმის პროცენტი</span><div class="flex items-center gap-2"><input type="number" min="0.01" max="100" step="0.01" wire:model="percentage" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"><span>%</span></div></label>
        @if ($patientGroup === \App\Models\PatientGroup::ISRAEL_PARTNER_SLUG)
            <label class="space-y-1 text-sm">
                <span class="font-medium">გადახდის ვალუტა</span>
                <select wire:model.live="paymentCurrency" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="GEL">GEL</option>
                    <option value="USD">USD</option>
                </select>
            </label>
            <label class="space-y-1 text-sm">
                <span class="font-medium">GEL/USD კურსი</span>
                <input type="number" min="0.000001" step="0.000001" wire:model.live.debounce.300ms="exchangeRate" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
                @error('exchangeRate') <div class="text-xs text-danger-600">{{ $message }}</div> @enderror
            </label>
        @endif
        <div class="flex gap-2 md:col-span-7"><x-filament::button wire:click="calculate" size="sm">დათვლა</x-filament::button></div>
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

        @if ($patientGroup === \App\Models\PatientGroup::ISRAEL_PARTNER_SLUG && isset($report['totals']['GEL']))
            @php
                $gelSalaryBasis = (float) $report['totals']['GEL']['doctor_share'];
                $previewPayment = $paymentCurrency === 'USD' && (float) $exchangeRate > 0
                    ? round($gelSalaryBasis / (float) $exchangeRate, 2)
                    : $gelSalaryBasis;
            @endphp
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-info-200 bg-info-50 px-3 py-2 text-xs dark:border-info-500/30 dark:bg-info-500/10">
                <span>GEL საფუძველი: <strong>{{ \App\Support\Currency::format($gelSalaryBasis, 'GEL') }}</strong></span>
                @if ($paymentCurrency === 'USD')
                    <span>კურსი: <strong>{{ (float) $exchangeRate > 0 ? number_format((float) $exchangeRate, 6) : '—' }}</strong></span>
                    @if ((float) $exchangeRate > 0)
                        @include('filament.resources.doctors.israeli-salary-payout', ['editable' => true])
                    @endif
                @else
                    <span class="text-info-700 dark:text-info-300">გასაცემი GEL: <strong>{{ \App\Support\Currency::format($previewPayment, 'GEL') }}</strong></span>
                @endif
            </div>
        @endif

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
                        <tr>
                            <td class="whitespace-nowrap p-3">{{ $row['visit_date'] }}</td>
                            <td class="p-3">{{ $row['patient'] }}</td>
                            <td class="p-3">
                                <details>
                                    <summary class="cursor-pointer">{{ collect($row['items'])->take(2)->pluck('name')->implode(', ') }}{{ count($row['items']) > 2 ? ' + '.(count($row['items']) - 2).' სხვა' : '' }}</summary>
                                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                                        @foreach ($row['items'] as $item)
                                            <div>
                                                {{ $item['name'] }} ×{{ $item['quantity'] }}
                                                @if (isset($item['unit_rate']))
                                                    · {{ \App\Support\Currency::format($item['unit_rate'], $row['currency']) }}/unit
                                                @endif
                                                — {{ \App\Support\Currency::format($item['revenue'], $row['currency']) }}
                                                @if ($item['source_type'] === 'visit')
                                                    / ხარჯი {{ \App\Support\Currency::format($item['direct_expense'], $row['currency']) }}
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </td>
                            <td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['work_total'], $row['currency']) }}</td>
                            <td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['expense_total'], $row['currency']) }}</td>
                            <td class="whitespace-nowrap p-3 text-right">{{ \App\Support\Currency::format($row['base_total'], $row['currency']) }}</td>
                            <td class="whitespace-nowrap p-3 text-right font-semibold">{{ \App\Support\Currency::format($row['doctor_share'], $row['currency']) }}</td>
                        </tr>
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
                        $visits = $settlement->items->groupBy(fn ($item) => $item->visit_id ? 'visit-'.$item->visit_id : 'lab-'.$item->labMainWork?->lab_case_id);
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
                                    @if ($settlement->incomingOwnerShares->isNotEmpty())
                                        @php
                                            $ownerSource = $settlement->incomingOwnerShares->first();
                                        @endphp
                                        <span class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300"
                                              title="{{ $ownerSource->sourceDoctor?->full_name }} · Visit #{{ $ownerSource->visit_id }} · Net basis {{ \App\Support\Currency::format((float) $settlement->base_total, $settlement->currency) }} · Received {{ \App\Support\Currency::format((float) $settlement->owner_split_received_total, $settlement->currency) }}">
                                            OWNER SPLIT
                                        </span>
                                        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                            Owner Split — {{ $ownerSource->sourceDoctor?->owner_split_key === 'nodar' ? 'ნოდარისგან' : 'ლევანისგან' }}
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
                                    @if (filled($settlement->payment_currency))
                                        @if ($settlement->total_paid_gel !== null && (float) $settlement->clinic_gel_used > 0)
                                            <span class="text-gray-600 dark:text-gray-300">
                                                {{ number_format((float) $settlement->total_paid_gel, 2) }} GEL paid —
                                                {{ number_format((float) $settlement->israeli_gel_used, 2) }} Israeli /
                                                {{ number_format((float) $settlement->clinic_gel_used, 2) }} Clinic
                                            </span>
                                        @endif
                                        @if ($settlement->actual_paid_usd !== null)
                                            <span>Calculated: {{ number_format((float) $settlement->calculated_usd, 2) }} USD
                                                · Actual paid: {{ number_format((float) $settlement->actual_paid_usd, 2) }} USD
                                                · Difference: {{ number_format((float) $settlement->difference_usd, 2) }} USD
                                                · Opening carry: {{ number_format((float) $settlement->opening_carry_usd, 2) }} USD
                                                · Closing carry: {{ number_format((float) $settlement->closing_carry_usd, 2) }} USD
                                                (positive = advance; negative = remaining)
                                            </span>
                                        @endif
                                        <span class="whitespace-nowrap text-info-600 dark:text-info-400">
                                            გადახდილი <strong>{{ \App\Support\Currency::format((float) $settlement->payment_amount, $settlement->payment_currency) }}</strong>
                                            @if (filled($settlement->payment_exchange_rate))
                                                · კურსი {{ number_format((float) $settlement->payment_exchange_rate, 6) }}
                                            @endif
                                        </span>
                                    @endif
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

                            @if ($settlement->incomingOwnerShares->isNotEmpty())
                                <div class="space-y-1.5 rounded-lg border border-primary-100 bg-primary-50/40 px-3 py-2 text-xs dark:border-primary-500/20 dark:bg-primary-500/5">
                                    @foreach ($settlement->incomingOwnerShares as $ownerShare)
                                        @php
                                            $sourceItems = $ownerShare->sourceSettlement?->items?->where('visit_id', $ownerShare->visit_id) ?? collect();
                                        @endphp
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <span class="font-semibold text-primary-700 dark:text-primary-300">OWNER SPLIT</span>
                                                · From {{ $ownerShare->sourceDoctor?->owner_split_key === 'nodar' ? 'Nodar' : 'Levan' }}
                                                · Visit #{{ $ownerShare->visit_id }}
                                                · Settlement #{{ $ownerShare->source_salary_settlement_id }}
                                                @if ($sourceItems->isNotEmpty())
                                                    <span class="text-gray-500">· {{ $sourceItems->map(fn ($item) => $item->visitTreatmentCase?->display_name)->filter()->implode(', ') }}</span>
                                                @endif
                                            </div>
                                            <strong class="whitespace-nowrap text-primary-700 dark:text-primary-300">
                                                {{ \App\Support\Currency::format((float) $ownerShare->amount, $ownerShare->currency) }}
                                            </strong>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

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
                                        @foreach ($visits as $sourceKey => $items)
                                            @php
                                                $firstItem = $items->first();
                                                $sum = fn (string $snapshot, string $legacy): float => (float) $items->sum(
                                                    fn ($item): float => (float) ($item->{$snapshot} ?? $item->{$legacy})
                                                );
                                            @endphp
                                            <tr class="align-top">
                                                <td class="whitespace-nowrap px-2.5 py-2 font-medium">{{ $firstItem?->visit_id ? '#'.$firstItem->visit_id : 'Lab #'.$firstItem?->labMainWork?->lab_case_id }}</td>
                                                <td class="whitespace-nowrap px-2.5 py-2">{{ $firstItem?->visit?->visit_date?->format('d.m.Y') ?? $firstItem?->labMainWork?->labCase?->case_date?->format('d.m.Y') ?? '—' }}</td>
                                                <td class="px-2.5 py-2">{{ $firstItem?->visit?->patient?->full_name ?? $firstItem?->labMainWork?->labCase?->patient?->lab_name ?? '—' }}</td>
                                                <td class="max-w-sm px-2.5 py-2">
                                                    <div class="space-y-0.5">
                                                        @foreach ($items as $item)
                                                            <div>
                                                                {{ $item->visitTreatmentCase?->display_name ?? ($item->labMainWork ? 'Zircon' : '—') }}
                                                                <span class="whitespace-nowrap text-gray-500">
                                                                    ×{{ max(1, (int) ($item->quantity_snapshot ?? $item->visitTreatmentCase?->quantity ?? 1)) }}
                                                                    @if ($item->unit_rate_snapshot)
                                                                        · {{ \App\Support\Currency::format((float) $item->unit_rate_snapshot, $settlement->currency) }}/unit
                                                                    @endif
                                                                </span>
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
