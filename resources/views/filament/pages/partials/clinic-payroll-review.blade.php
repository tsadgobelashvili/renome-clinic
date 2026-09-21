@if($review)
    <div class="space-y-4 text-sm">
        <div class="flex flex-wrap justify-between gap-2 font-semibold">
            <span>{{ __('salaries.clinic') }} — {{ \Carbon\CarbonImmutable::parse($review['payroll_date'])->format('d.m.Y') }}</span>
            <span>{{ __('salaries.doctors') }}: {{ count($review['doctors']) }} · {{ __('salaries.employees') }}: {{ count($review['employees']) }}</span>
        </div>
        @if(isset($review['doctor_settlement_ids']))
            <p class="text-xs text-gray-500">{{ __('clinic-payroll.finalized') }}</p>
        @else
            <p class="text-xs text-gray-500">{{ __('clinic-payroll.confirmation') }}</p>
        @endif
        @error('payroll')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
        <div class="max-h-[55vh] space-y-4 overflow-auto">
            <section>
                <h3 class="mb-2 font-semibold">{{ __('salaries.doctors') }}</h3>
                <table class="w-full text-xs">
                    <thead class="text-left text-gray-500"><tr><th class="p-2">{{ __('salaries.doctor') }}</th><th class="p-2">{{ __('salaries.period') }}</th><th class="p-2">{{ __('salaries.payment_method') }}</th><th class="p-2 text-right">{{ __('salaries.payable') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse($review['doctors'] as $row)
                            <tr><td class="p-2 font-medium">{{ $row['name'] }}</td><td class="p-2">{{ \Carbon\CarbonImmutable::parse($row['period_start'])->format('d.m.Y') }} — {{ \Carbon\CarbonImmutable::parse($row['period_end'])->format('d.m.Y') }}</td><td class="p-2">{{ $row['payment_method'] ? __('employees.payroll.'.$row['payment_method']) : '—' }}</td><td class="p-2 text-right tabular-nums">@foreach($row['amounts'] as $currency => $amount)<div>{{ \App\Support\Currency::format($amount, $currency) }}</div>@endforeach</td></tr>
                        @empty<tr><td colspan="4" class="p-2 text-gray-500">—</td></tr>@endforelse
                    </tbody>
                </table>
            </section>
            <section>
                <h3 class="mb-2 font-semibold">{{ __('salaries.employees') }}</h3>
                <table class="w-full text-xs">
                    <thead class="text-left text-gray-500"><tr><th class="p-2">{{ __('salaries.person') }}</th><th class="p-2 text-right">{{ __('employees.payroll.net_amount') }}</th><th class="p-2 text-right">{{ __('employees.payroll.funding_required') }}</th><th class="p-2">{{ __('salaries.payment_method') }}</th><th class="p-2">{{ __('salaries.payday') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse($review['employees'] as $row)
                            @if (($row['salary_advance_applied'] ?? 0) > 0)
                                <tr><td colspan="5" class="p-2 text-gray-600">{{ $row['name'] }} · დარიცხული ხელფასი: {{ \App\Support\Currency::format($row['net_amount'], $row['currency']) }} · ხელფასის ავანსი: {{ \App\Support\Currency::format($row['salary_advance_applied'], $row['currency']) }} · დარჩენილი გადასახდელი: {{ \App\Support\Currency::format($row['amount_payable'], $row['currency']) }}</td></tr>
                            @endif
                            <tr><td class="p-2 font-medium">{{ $row['name'] }}</td><td class="p-2 text-right tabular-nums">{{ \App\Support\Currency::format($row['net_amount'], $row['currency']) }}</td><td class="p-2 text-right font-semibold tabular-nums">{{ \App\Support\Currency::format($row['required_amount'], $row['currency']) }}</td><td class="p-2">{{ __('employees.payroll.'.$row['payment_method']) }}</td><td class="p-2">{{ \Carbon\CarbonImmutable::parse($row['payday'])->format('d.m.Y') }}</td></tr>
                        @empty<tr><td colspan="5" class="p-2 text-gray-500">—</td></tr>@endforelse
                    </tbody>
                </table>
            </section>
        </div>
        <dl class="space-y-2 border-t border-gray-200 pt-3 dark:border-white/10">
            @foreach(['doctor_totals' => 'doctors_total', 'employee_totals' => 'employees_total', 'totals' => 'grand_total'] as $key => $label)
                <div class="flex justify-between gap-3 {{ $key === 'totals' ? 'font-bold' : '' }}"><dt>{{ __('clinic-payroll.'.$label) }}</dt><dd class="text-right tabular-nums">@forelse($review[$key] as $currency => $amount)<div>{{ \App\Support\Currency::format($amount, $currency) }}</div>@empty — @endforelse</dd></div>
            @endforeach
        </dl>
    </div>
@endif
