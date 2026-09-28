@if($details->first() && isset($details->first()->row_count))
    @foreach($details as $group)
        <details class="border-b border-gray-200 p-2" wire:key="salary-group-{{ md5($group->display_group.$group->currency) }}">
            <summary class="cursor-pointer"><span>{{ $group->rows->first()->counterparty ?: $group->rows->first()->description }}</span>
                <span class="float-right font-semibold">{{ number_format($group->total, 2) }} {{ $group->currency }} · {{ $group->row_count }} ჩანაწერი</span>
            </summary>
            @include('filament.pages.finance-overview-entries', ['details' => $group->rows])
        </details>
    @endforeach
    {{ $details->links() }}
@else
<div class="overflow-x-auto">
    <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
        @foreach($overviewCard === 'revenue' ? ['date', 'source', 'method', 'patient', 'description', 'amount'] : ['date', 'source', 'counterparty', 'description', 'amount'] as $field)<th class="px-2 py-2 font-medium {{ $field === 'amount' ? 'text-right' : '' }}">{{ __('finance-overview.'.$field) }}</th>@endforeach
    </tr></thead><tbody>
        @forelse($details as $entry)
            <tr wire:key="overview-entry-{{ $entry->entry_key }}" class="even:bg-gray-50 dark:even:bg-white/5">
                <td class="whitespace-nowrap px-2 py-2">{{ \Carbon\Carbon::parse($entry->entry_date)->format('d.m.Y') }}</td>
                <td class="px-2 py-2 text-xs">{{ __('finance-overview.'.($overviewCard === 'revenue' ? $entry->business_source : $entry->source)) }}
                    @if ($overviewCard !== 'revenue' && $entry->source === 'cash' && in_array($entry->business_source, ['clinic', 'israeli', 'mixed'], true))
                        - {{ $entry->business_source === 'mixed' ? __('finance-overview.clinic').' + '.__('finance-overview.israeli') : __('finance-overview.'.$entry->business_source) }}
                    @endif</td>
                @if($overviewCard === 'revenue')<td class="whitespace-nowrap px-2 py-2 text-xs">{{ $entry->payment_method ? \App\Enums\PaymentMethod::labelFor($entry->payment_method) : '—' }} · {{ $entry->currency }}</td>@endif
                <td class="px-2 py-2">{{ $entry->counterparty ?: '—' }}</td>
                <td class="max-w-80 px-2 py-2"><p>{{ $entry->description ?: __('finance-overview.origins.'.$entry->origin) }}</p>@if($entry->category_name)<p class="text-xs text-gray-500">{{ $entry->category_name }}</p>@endif</td>
                <td class="whitespace-nowrap px-2 py-2 text-right font-medium tabular-nums {{ $entry->is_transfer ? 'text-gray-500' : (in_array($entry->metric, ['expense', 'outflow']) ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ in_array($entry->metric, ['outflow']) ? '−' : '' }}{{ number_format($entry->amount, 2) }} {{ $entry->currency }}</td>
            </tr>
        @empty<tr><td colspan="{{ $overviewCard === 'revenue' ? 6 : 5 }}" class="px-2 py-5 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>@endforelse
    </tbody></table>
</div>
@if(method_exists($details, 'links'))<div class="mt-2">{{ $details->links() }}</div>@endif

@endif
