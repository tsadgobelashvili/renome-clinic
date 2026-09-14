<div class="mb-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <button type="button" wire:click="mountAction('clinicPayroll')" class="flex w-full flex-wrap items-center justify-between gap-3 text-left" data-clinic-payroll-date="{{ $clinicPayroll['payroll_date'] }}">
        <div>
            <div class="text-sm font-semibold">{{ __('employees.payroll.upcoming_requirement') }} — {{ \Carbon\CarbonImmutable::parse($clinicPayroll['payroll_date'])->format('d.m.Y') }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ __('salaries.clinic') }} · {{ __('salaries.doctors') }} + {{ __('salaries.employees') }} · {{ __('clinic-payroll.review') }}</div>
        </div>
        <div class="text-right text-lg font-semibold tabular-nums">
            @foreach($clinicPayroll['totals'] as $currency => $amount)<div>{{ \App\Support\Currency::format($amount, $currency) }}</div>@endforeach
        </div>
    </button>
    @if($lastClinicPayroll)
        <button type="button" wire:click="mountAction('clinicPayroll', { cycle: {{ $lastClinicPayroll->id }} })" class="mt-2 text-xs text-primary-600">{{ __('clinic-payroll.last_finalized') }} — {{ $lastClinicPayroll->payroll_date->format('d.m.Y') }}</button>
    @endif
</div>
<section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex gap-2 p-3" aria-label="{{ __('salaries.filters') }}">
        @foreach (['doctors', 'employees'] as $staff)
            <x-filament::button type="button" :color="$staffTypeFilter === $staff ? 'primary' : 'gray'" wire:click="$set('staffTypeFilter', '{{ $staff }}')" :aria-pressed="$staffTypeFilter === $staff ? 'true' : 'false'">
                {{ __('salaries.'.$staff) }}
            </x-filament::button>
        @endforeach
    </div>

    <div class="overflow-x-auto border-t border-gray-100 dark:border-white/10">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('salaries.person') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('salaries.role') }}</th>
                    <th class="px-3 py-2 text-left">{{ __($staffTypeFilter === 'employees' ? 'employees.payroll.net_amount' : 'salaries.payable') }}</th>
                    @if($staffTypeFilter === 'employees')<th class="px-3 py-2 text-right">{{ __('employees.payroll.funding_required') }}</th>@endif
                    <th class="px-3 py-2 text-center">{{ __('salaries.payday') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('salaries.payment_method') }}</th>
                    <th class="w-9 px-2 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($staffRows as $row)
                    @php($open = $row['type'] === 'doctor' ? "openDoctorSalary({$row['id']}, '{$row['source']}')" : ($row['can_open'] ? "openEmployeeSalary({$row['id']}, '{$row['source']}', {$row['entry_id']})" : null))
                    <tr wire:key="salary-row-{{ $row['key'] }}" @if ($open) role="button" tabindex="0" wire:click="{{ $open }}" wire:keydown.enter="{{ $open }}" wire:keydown.space.prevent="{{ $open }}" @endif class="group transition-colors hover:bg-primary-50/60 focus:bg-primary-50/60 focus:outline-none dark:hover:bg-primary-500/5 {{ $open ? 'cursor-pointer' : '' }}">
                        <td class="px-3 py-3 font-semibold text-gray-950 group-hover:text-primary-700 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $row['role'] }}</td>
                        <td class="px-3 py-3 font-semibold tabular-nums text-gray-950 dark:text-white">
                            @if ($row['type'] === 'doctor')
                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach (['clinic', 'israeli'] as $source)
                                        @if (! $loop->first)<span class="text-gray-300" aria-hidden="true">/</span>@endif
                                        <button type="button" wire:click.stop="openDoctorSalary({{ $row['id'] }}, '{{ $source }}')" wire:keydown.enter.stop wire:keydown.space.stop class="rounded px-1 py-0.5 hover:bg-gray-100 dark:hover:bg-white/10">
                                            <span class="text-xs font-medium text-gray-500">{{ __('salaries.'.$source) }}</span>
                                            @foreach ($row['sources'][$source] ?? ['GEL' => 0] as $currency => $amount)
                                                <span class="whitespace-nowrap">{{ \App\Support\Currency::format($amount, $currency) }}</span>
                                            @endforeach
                                        </button>
                                    @endforeach
                                </div>
                            @else
                                @if ($row['source'])<span class="mr-1 text-xs font-medium text-gray-500">{{ __('salaries.'.$row['source']) }}</span>@endif
                                @if (! $row['salary_configured'])<span class="text-gray-500">{{ __('salaries.salary_not_configured') }}</span>@endif
                                @foreach ($row['amounts'] as $currency => $amount)
                                    <span class="whitespace-nowrap">{{ \App\Support\Currency::format($amount, $currency) }}</span>
                                @endforeach
                            @endif
                        </td>
                        @if($staffTypeFilter === 'employees')
                            <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums">@forelse($row['required_amounts'] as $currency => $amount){{ \App\Support\Currency::format($amount, $currency) }}@empty—@endforelse</td>
                        @endif
                        <td class="whitespace-nowrap px-3 py-3 text-center tabular-nums">{{ $row['payday'] ? \Carbon\CarbonImmutable::parse($row['payday'])->format('d.m.Y') : '—' }}</td>
                        <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $row['payment_method'] ? __('employees.payroll.'.$row['payment_method']) : '—' }}</td>
                        <td class="px-2 py-3 text-right text-gray-400 group-hover:text-primary-500" aria-hidden="true">{{ $open ? '›' : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $staffTypeFilter === 'employees' ? 7 : 6 }}" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('salaries.no_rows') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
