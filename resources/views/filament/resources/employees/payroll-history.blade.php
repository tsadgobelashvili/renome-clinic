<div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
            <tr>
                <th class="px-3 py-2 text-left">{{ __('employees.payroll.period') }}</th>
                <th class="px-3 py-2 text-left">{{ __('employees.payroll.source') }}</th>
                <th class="px-3 py-2 text-left">{{ __('employees.payroll.salary_model') }}</th>
                <th class="px-3 py-2 text-right">{{ __('employees.payroll.gross_amount') }}</th>
                <th class="px-3 py-2 text-right">{{ __('employees.payroll.net_amount') }}</th>
                <th class="px-3 py-2 text-left">{{ __('employees.payroll.payment_method') }}</th>
                <th class="px-3 py-2 text-left">{{ __('employees.salary.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap px-3 py-2">{{ $entry->period_start->format('d.m.Y') }}–{{ $entry->period_end->format('d.m.Y') }}</td>
                    <td class="px-3 py-2">{{ __('employees.payroll.'.$entry->source) }}</td>
                    <td class="px-3 py-2">{{ __('employees.payroll.'.$entry->salary_model) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ \App\Support\Currency::format($entry->gross_amount, $entry->currency) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right font-bold tabular-nums">{{ \App\Support\Currency::format($entry->net_amount, $entry->currency) }}</td>
                    <td class="px-3 py-2">{{ __('employees.payroll.'.$entry->payment_method) }}</td>
                    <td class="px-3 py-2">{{ __('employees.payroll.'.$entry->payout_status) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500">{{ __('employees.payroll.no_history') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
