<x-filament-panels::page>
    <div class="space-y-4" data-doctor-compensation-page>
        @include('filament.pages.partials.salaries-overview')

        <x-filament::modal id="employee-payroll-detail" width="4xl">
            <x-slot name="heading">{{ __('salaries.employee_details') }}</x-slot>
            @if ($employeeDetail)
                <div wire:key="employee-salary-modal-{{ $employeeDetail['employee_id'] }}-{{ $employeeDetail['source'] }}" class="max-h-[72vh] space-y-4 overflow-y-auto pr-1">
                    <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div>
                            <div class="font-semibold text-gray-950 dark:text-white">{{ $employeeDetail['name'] }}</div>
                            <div class="text-xs text-gray-500">{{ $employeeDetail['role'] }} · {{ __('salaries.'.$employeeDetail['source']) }}</div>
                        </div>
                        <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">{{ __('salaries.'.$employeeDetail['status']) }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        @foreach ([
                            [__('salaries.salary_model'), __('employees.payroll.'.$employeeDetail['salary_model'])],
                            [__('salaries.configured_rule'), $employeeDetail['configured_rule']],
                            [__('salaries.period'), \Carbon\CarbonImmutable::parse($employeeDetail['period_start'])->format('d.m.Y').'–'.\Carbon\CarbonImmutable::parse($employeeDetail['period_end'])->format('d.m.Y')],
                            [__('employees.payroll.base_amount'), \App\Support\Currency::format($employeeDetail['base_amount'], $employeeDetail['currency'])],
                            [__('employees.payroll.gross_amount'), \App\Support\Currency::format($employeeDetail['gross_amount'], $employeeDetail['currency'])],
                            [__('employees.payroll.deductions'), \App\Support\Currency::format($employeeDetail['deductions'], $employeeDetail['currency'])],
                            [__('employees.payroll.net_amount'), \App\Support\Currency::format($employeeDetail['net_amount'], $employeeDetail['currency'])],
                            [__('salaries.payment_method'), __('employees.payroll.'.$employeeDetail['payment_method'])],
                            [__('salaries.expected_date'), $employeeDetail['expected_date'] ? \Carbon\CarbonImmutable::parse($employeeDetail['expected_date'])->format('d.m.Y') : '—'],
                        ] as [$label, $value])
                            <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                                <dt class="text-[11px] font-medium text-gray-500">{{ $label }}</dt>
                                <dd class="mt-0.5 font-semibold text-gray-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="sticky bottom-0 flex justify-end gap-2 border-t border-gray-100 bg-white/95 pt-3 backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                        <x-filament::button type="button" color="gray" x-on:click="$dispatch('close-modal', { id: 'employee-payroll-detail' })">{{ __('salaries.close') }}</x-filament::button>
                        @if ($employeeDetail['can_finalize'] && auth()->user()?->isOwner())
                            <x-filament::button type="button" wire:click="finalizeEmployeePayroll" wire:loading.attr="disabled">{{ __('employees.payroll.finalize') }}</x-filament::button>
                        @endif
                    </div>
                </div>
            @endif
        </x-filament::modal>
    </div>
</x-filament-panels::page>
