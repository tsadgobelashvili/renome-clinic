<section class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-white/10" data-israeli-payout-history>
    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('israeli-payout-history.title') }}</h3>
    <div class="max-h-80 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-left text-xs">
            <thead class="bg-gray-50 text-gray-500 dark:bg-white/5"><tr>
                @foreach(['date', 'total_paid', 'currency', 'method', 'status'] as $field)<th class="px-3 py-2">{{ __('israeli-payout-history.'.$field) }}</th>@endforeach
                <th class="px-3 py-2">{{ __('israeli-payout-history.details') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($payoutHistory as $entry)
                    <tr wire:key="israeli-history-{{ $entry['key'] }}" class="even:bg-gray-50 dark:even:bg-white/5">
                        <td class="whitespace-nowrap px-3 py-2">{{ $entry['date']->format('d.m.Y H:i') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-semibold tabular-nums">
                            @foreach(collect($entry['payments'])->groupBy('currency') as $currency => $payments)
                                <div>{{ \App\Support\Currency::format($payments->sum('amount'), $currency) }}</div>
                            @endforeach
                            @if($entry['payments'] === [])<span>0.00 ₾</span>@endif
                            @if($entry['deducted'] > 0)<div class="font-normal text-gray-500">{{ __('israeli-payout-history.deducted') }}: {{ \App\Support\Currency::format($entry['deducted'], 'GEL') }}</div>@endif
                        </td>
                        <td class="px-3 py-2">{{ collect($entry['payments'])->pluck('currency')->unique()->implode(' / ') ?: 'GEL' }}</td>
                        <td class="px-3 py-2">@foreach(collect($entry['payments'])->pluck('method')->unique() as $method)<div>{{ $method ? __('israeli-payout-history.'.($method === 'bank_transfer' ? 'bank' : $method)) : __('israeli-payout-history.unknown') }}</div>@endforeach</td>
                        <td class="px-3 py-2">{{ __('israeli-payout-history.'.($entry['status'] === 'paid' ? 'complete' : $entry['status'])) }}</td>
                        <td class="px-3 py-2"><x-filament::button size="xs" color="gray" wire:click="mountAction('israeliPayoutDetails', {{ \Illuminate\Support\Js::from(['key' => $entry['key']]) }})">{{ __('israeli-payout-history.details') }}</x-filament::button></td>
                    </tr>
                @empty<tr><td colspan="6" class="px-3 py-4 text-gray-500">{{ __('israeli-payout-history.empty') }}</td></tr>@endforelse
            </tbody>
        </table>
    </div>
</section>
