<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
            @foreach(['date', 'type', 'source', 'description', 'amount'] as $field)<th class="px-2 py-2 {{ $field === 'amount' ? 'text-right' : '' }}">{{ __('finance-overview.'.$field) }}</th>@endforeach
        </tr></thead>
        <tbody>
            @forelse($details as $entry)
                <tr wire:key="outflow-entry-{{ $entry->entry_key }}" class="even:bg-gray-50 dark:even:bg-white/5">
                    <td class="whitespace-nowrap px-2 py-2">{{ \Carbon\Carbon::parse($entry->entry_date)->format('d.m.Y') }}</td>
                    <td class="px-2 py-2">{{ __('finance-overview.origins.'.$entry->origin) }}</td>
                    <td class="px-2 py-2">{{ $entry->business_source ? __('finance-overview.'.$entry->business_source) : '—' }}</td>
                    <td class="max-w-80 px-2 py-2">{{ $entry->description ?: '—' }}</td>
                    <td class="whitespace-nowrap px-2 py-2 text-right font-medium tabular-nums {{ $entry->is_transfer ? 'text-gray-700 dark:text-gray-300' : 'text-rose-600 dark:text-rose-400' }}">{{ number_format($entry->amount, 2) }} {{ $entry->currency }}</td>
                </tr>
            @empty<tr><td colspan="5" class="px-2 py-4 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-2">{{ $details->links() }}</div>
