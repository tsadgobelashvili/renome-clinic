<div class="overflow-x-auto">
    <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
        @foreach(['date', 'source', 'type', 'description', 'amount'] as $field)<th class="px-2 py-2 font-medium {{ $field === 'amount' ? 'text-right' : '' }}">{{ __('finance-overview.'.$field) }}</th>@endforeach
    </tr></thead><tbody>
        @forelse($details as $entry)
            <tr wire:key="cash-movement-{{ $entry->entry_key }}" class="even:bg-gray-50 dark:even:bg-white/5">
                <td class="whitespace-nowrap px-2 py-2">{{ \Carbon\Carbon::parse($entry->entry_date)->format('d.m.Y') }}</td>
                <td class="px-2 py-2 text-xs">{{ __('finance-overview.cash_sources.'.$entry->cash_source) }}
                    @if($entry->business_source)<p class="text-gray-500">{{ __('finance-overview.'.$entry->business_source) }}</p>@endif
                </td>
                <td class="px-2 py-2 text-xs">
                    @if($entry->metric === 'internal_transfer'){{ __('finance-overview.internal_transfer') }}
                    @elseif($entry->group_key === 'bank_deposit'){{ __('finance-overview.outflow_groups.bank_deposit') }}
                    @else{{ __('finance-overview.origins.'.$entry->origin) }}@endif
                    @if($entry->metric !== 'internal_transfer')<p class="text-gray-500">{{ __('finance-overview.cash_directions.'.$entry->metric) }}</p>@endif
                </td>
                <td class="max-w-80 px-2 py-2">{{ $entry->description ?: '—' }}</td>
                <td class="whitespace-nowrap px-2 py-2 text-right font-medium tabular-nums {{ $entry->metric === 'internal_transfer' ? 'text-gray-500' : ($entry->metric === 'outflow' ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $entry->metric === 'outflow' ? '−' : ($entry->metric === 'inflow' ? '+' : '↔') }}{{ number_format($entry->amount, 2) }} {{ $entry->currency }}</td>
            </tr>
        @empty<tr><td colspan="5" class="px-2 py-5 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>@endforelse
    </tbody></table>
</div>
@if(method_exists($details, 'links'))<div class="mt-2">{{ $details->links() }}</div>@endif
