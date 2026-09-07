<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                <th class="w-8"><span class="sr-only">{{ __('employees.salary.select') }}</span></th>
                <th>{{ __('employees.salary.date') }}</th><th>{{ __('lab.patient') }}</th>
                <th>{{ __('lab.work_type') }}</th><th class="text-right">{{ __('lab.qty') }}</th>
                <th class="text-right">{{ __('employees.salary.rate') }}</th><th class="text-right">{{ __('employees.salary.total') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $key => $row)
                <tr wire:key="employee-salary-{{ $key }}">
                    <td class="text-center"><x-filament::input.checkbox :value="$key" :disabled="$isDisabled()"
                        :attributes="new \Illuminate\View\ComponentAttributeBag([$applyStateBindingModifiers('wire:model') => $getStatePath(), 'aria-label' => $row['patient_name'].' — '.(\App\Models\EmployeeSalaryRate::workTypes()[$row['work_type']] ?? $row['work_type'])])" /></td>
                    <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($row['work_date'])->format('d.m.Y') }}</td>
                    <td class="font-semibold">{{ $row['patient_name'] }}</td>
                    <td>{{ \App\Models\EmployeeSalaryRate::workTypes()[$row['work_type']] ?? $row['work_type'] }}</td>
                    <td class="text-right tabular-nums">{{ $row['quantity'] }}</td>
                    <td class="text-right tabular-nums">{{ number_format($row['rate_amount'], 2) }} ₾ <span class="text-gray-500">/ {{ __('employees.salary.'.$row['rate_basis']) }}</span></td>
                    <td class="text-right font-semibold tabular-nums">{{ number_format($row['amount_gel'], 2) }} ₾</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-gray-500">{{ __('employees.salary.no_pending') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-dynamic-component>
