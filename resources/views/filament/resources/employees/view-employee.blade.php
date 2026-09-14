<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $record->full_name }}</x-slot>
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500">
            <span>{{ $record->position?->name }}</span>
            <span>{{ __('employees.active') }}: {{ $record->is_active ? __('employees.salary.yes') : __('employees.salary.no') }}</span>
            <span>{{ __('employees.phone') }}: {{ $record->phone ?: '—' }}</span>
            <span>{{ __('employees.birth_date') }}: {{ $record->birth_date?->format('d.m.Y') ?? '—' }}</span>
            <span>{{ __('employees.personal_id') }}: {{ $record->personal_id ?: '—' }}</span>
            <span>{{ __('employees.salary.payment_schedule') }}: {{ $record->salary_payment_schedule ?: '—' }}</span>
        </div>
    </x-filament::section>
    @if($record->position?->is_technician)
        <x-filament::section>
            <x-slot name="heading">{{ __('employees.salary.title') }}</x-slot>
            <div class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                <span>{{ __('employees.salary.type') }}: <strong class="text-gray-800 dark:text-gray-200">{{ $record->salary_type ? __('employees.salary.'.$record->salary_type) : '—' }}</strong></span>
                <span>{{ __('employees.salary.active') }}: <strong class="text-gray-800 dark:text-gray-200">{{ $record->salary_active ? __('employees.salary.yes') : __('employees.salary.no') }}</strong></span>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr><th>{{ __('lab.work_type') }}</th><th class="text-right">{{ __('employees.salary.rate') }}</th><th>{{ __('employees.salary.basis') }}</th><th class="text-center">{{ __('employees.active') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse($record->salaryRates->sortBy('work_type') as $rate)
                            <tr>
                                <td class="font-semibold">{{ \App\Models\EmployeeSalaryRate::workTypes()[$rate->work_type] ?? $rate->work_type }}</td>
                                <td class="text-right tabular-nums">{{ number_format((float) $rate->amount, 2) }} ₾</td>
                                <td>{{ __('employees.salary.'.$rate->basis) }}</td>
                                <td class="text-center">{{ $rate->is_active ? __('employees.salary.yes') : __('employees.salary.no') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-gray-500">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('employees.payroll.title') }}</x-slot>
            <div class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('employees.payroll.payout_day') }}:
                <strong class="text-gray-800 dark:text-gray-200">{{ $record->salary_payout_day ?: '—' }}</strong>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                @foreach (['clinic', 'israeli'] as $source)
                    @php($setting = $record->payrollSettings->firstWhere('source', $source))
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <strong class="text-gray-950 dark:text-white">{{ __('employees.payroll.'.$source) }}</strong>
                            @if($setting)
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $setting->is_active,
                                    'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => ! $setting->is_active,
                                ])>{{ $setting->is_active ? __('employees.salary.active') : __('employees.payroll.inactive') }}</span>
                            @endif
                        </div>
                        @if($setting)
                            <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5">
                                <dt class="text-gray-500">{{ __('employees.payroll.salary_model') }}</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ __('employees.payroll.'.$setting->salary_model) }}</dd>
                                <dt class="text-gray-500">{{ __('employees.payroll.amount_rate') }}</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">
                                    @if($setting->salary_model === 'percentage')
                                        {{ number_format((float) $setting->percentage_rate, 2) }}%
                                    @elseif($setting->salary_model === 'fixed_net')
                                        {{ \App\Support\Currency::format($setting->net_amount, $setting->currency) }}
                                    @elseif($setting->salary_model === 'fixed_gross')
                                        {{ \App\Support\Currency::format($setting->gross_amount, $setting->currency) }}
                                    @else
                                        {{ \App\Support\Currency::format($setting->per_unit_amount, $setting->currency) }} / {{ __('employees.payroll.unit') }}
                                    @endif
                                </dd>
                                @if($source === 'clinic' && $setting->salary_model === 'fixed_net')
                                    <dt class="text-gray-500">{{ __('employees.payroll.funding_required') }}</dt>
                                    <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ \App\Support\Currency::format(\App\Services\ClinicEmployeePayrollAmounts::fromNet($setting->net_amount, $setting->default_payment_method)['required_amount'], $setting->currency) }}</dd>
                                @endif
                                <dt class="text-gray-500">{{ __('employees.payroll.payment_method') }}</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ __('employees.payroll.'.$setting->default_payment_method) }}</dd>
                                <dt class="text-gray-500">{{ __('employees.payroll.effective_from') }}</dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ $setting->effective_from?->format('d.m.Y') ?? '—' }}</dd>
                            </dl>
                        @else
                            <p class="text-gray-500">{{ __('employees.payroll.not_configured') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    @if($record->position?->is_technician)
    <x-filament::section>
        <x-slot name="heading">{{ __('employees.performed_work') }}</x-slot>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>
                    <th>{{ __('lab.date') }}</th><th>{{ __('lab.patient') }}</th><th>{{ __('lab.work_type') }}</th>
                    <th class="text-right">{{ __('lab.qty') }}</th><th>{{ __('employees.work_role') }}</th><th>{{ __('lab.source') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->performedWorks() as $work)
                        <tr>
                            <td class="whitespace-nowrap">{{ $work['date']->format('d.m.Y') }}</td>
                            <td class="font-semibold">{{ $work['patient'] }}</td><td>{{ $work['work'] }}</td>
                            <td class="text-right tabular-nums">{{ $work['quantity'] }}</td><td>{{ $work['role'] }}</td>
                            <td>{{ __('lab.sources.'.$work['source']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-gray-500">{{ __('employees.no_performed_work') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    @endif
</x-filament-panels::page>
