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
    @if($staffTypeFilter === 'employees')
        <div class="flex gap-1 border-t border-gray-100 px-3 py-2 dark:border-white/10" role="group" aria-label="{{ __('salaries.payment_day_filter') }}" data-employee-payment-day-filter>
            @foreach (['1', '16', 'all'] as $day)
                <x-filament::button type="button" size="sm" :color="$employeePaymentDayFilter === $day ? 'primary' : 'gray'" wire:click="$set('employeePaymentDayFilter', '{{ $day }}')" :aria-pressed="$employeePaymentDayFilter === $day ? 'true' : 'false'">
                    {{ __('salaries.'.($day === 'all' ? 'all' : 'payment_day_'.$day)) }}
                </x-filament::button>
            @endforeach
        </div>
    @endif
    @include('filament.pages.partials.salary-staff-table')
</section>
