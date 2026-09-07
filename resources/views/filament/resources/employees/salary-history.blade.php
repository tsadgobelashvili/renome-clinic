<x-filament::section>
        <x-slot name="heading">{{ __('employees.salary.history') }}</x-slot>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-xs"><thead class="bg-gray-50 dark:bg-white/5"><tr><th>{{ __('employees.salary.date') }}</th><th>{{ __('employees.salary.period') }}</th><th class="text-right">{{ __('employees.salary.total') }}</th><th>{{ __('employees.salary.status') }}</th><th></th></tr></thead>
                <tbody>@forelse ($record->salarySettlements()->with('items')->latest('settled_at')->get() as $settlement)
                    <tr>
                        <td>{{ $settlement->settled_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $settlement->salary_month ?? (($settlement->period_from?->format('d.m.Y') ?? '—').' – '.($settlement->period_until?->format('d.m.Y') ?? '—')) }}</td>
                        <td class="text-right font-semibold tabular-nums">{{ number_format($settlement->total_gel, 2) }} ₾
                            @if ((float) $settlement->opening_carry_gel > 0)
                                <div class="text-xs font-normal text-gray-500">{{ __('employees.salary.previous_unpaid') }}: {{ number_format($settlement->opening_carry_gel, 2) }} ₾<br>{{ __('employees.salary.current_salary') }}: {{ number_format($settlement->current_salary_gel, 2) }} ₾</div>
                            @endif
                        </td>
                        <td>{{ __('employees.salary.'.$settlement->status) }}
                            @if ($settlement->actual_paid_gel !== null)
                                <div class="text-xs text-gray-500">{{ __('employees.salary.actual_paid') }}: {{ number_format($settlement->actual_paid_gel, 2) }} GEL<br>
                                    {{ __('employees.salary.clinic_cash') }}: {{ number_format($settlement->clinic_cash_gel, 2) }} / {{ __('employees.salary.israeli_cash') }}: {{ number_format($settlement->israeli_cash_gel, 2) }}
                                    @if ((float) $settlement->closing_carry_gel > 0)<br><span class="text-danger-600">{{ __('employees.salary.remaining') }}: {{ number_format($settlement->closing_carry_gel, 2) }} GEL</span>@endif
                                </div>
                            @endif
                        </td>
                        <td>@if ($settlement->status === 'confirmed')<x-filament::button size="xs" color="gray" wire:click="mountAction('undoSalary', { settlement: {{ $settlement->id }} })">{{ __('employees.salary.undo') }}</x-filament::button>@endif</td>
                    </tr>
                    @if ($settlement->items->isNotEmpty())
                        <tr><td colspan="5"><details><summary class="cursor-pointer text-gray-500">{{ __('employees.salary.details') }}</summary>
                            @foreach ($settlement->items as $item)
                                <div class="flex justify-between gap-3 py-1"><span>{{ $item->work_date->format('d.m.Y') }} · {{ $item->patient_name }} · {{ \App\Models\EmployeeSalaryRate::workTypes()[$item->work_type] ?? $item->work_type }} × {{ $item->quantity }}</span><span class="whitespace-nowrap tabular-nums">{{ number_format($item->amount_gel, 2) }} ₾</span></div>
                            @endforeach
                        </details></td></tr>
                    @endif
                @empty<tr><td colspan="5" class="text-center text-gray-500">{{ __('employees.salary.no_history') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
    </x-filament::section>
