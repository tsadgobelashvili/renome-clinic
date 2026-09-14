<div class="overflow-x-auto">
    <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
        @foreach($overviewCard === 'revenue' ? ['date', 'source', 'method', 'patient', 'description', 'amount'] : ['date', 'source', 'counterparty', 'description', 'amount'] as $field)<th class="px-2 py-2 font-medium {{ $field === 'amount' ? 'text-right' : '' }}">{{ __('finance-overview.'.$field) }}</th>@endforeach
    </tr></thead><tbody>
        @forelse($details as $entry)
            <tr wire:key="overview-entry-{{ $entry->entry_key }}" class="even:bg-gray-50 dark:even:bg-white/5">
                <td class="whitespace-nowrap px-2 py-2">{{ \Carbon\Carbon::parse($entry->entry_date)->format('d.m.Y') }}</td>
                <td class="px-2 py-2 text-xs">{{ __('finance-overview.'.($overviewCard === 'revenue' ? $entry->business_source : $entry->source)) }}</td>
                @if($overviewCard === 'revenue')<td class="whitespace-nowrap px-2 py-2 text-xs">{{ $entry->payment_method ? \App\Enums\PaymentMethod::labelFor($entry->payment_method) : '—' }} · {{ $entry->currency }}</td>@endif
                <td class="px-2 py-2">{{ $entry->counterparty ?: '—' }}</td>
                <td class="max-w-80 px-2 py-2"><p>{{ $entry->description ?: __('finance-overview.origins.'.$entry->origin) }}</p>@if($entry->category_name)<p class="text-xs text-gray-500">{{ $entry->category_name }}</p>@endif</td>
                <td class="whitespace-nowrap px-2 py-2 text-right font-medium tabular-nums {{ $entry->is_transfer ? 'text-gray-500' : (in_array($entry->metric, ['expense', 'outflow']) ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ in_array($entry->metric, ['outflow']) ? '−' : '' }}{{ number_format($entry->amount, 2) }} {{ $entry->currency }}</td>
            </tr>
        @empty<tr><td colspan="{{ $overviewCard === 'revenue' ? 6 : 5 }}" class="px-2 py-5 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>@endforelse
    </tbody></table>
</div>
<div class="mt-2">{{ $details->links() }}</div>
