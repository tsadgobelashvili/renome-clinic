<div class="space-y-4 text-sm" data-israeli-payout-detail="{{ $entry['key'] }}">
    <div class="grid gap-3 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5 sm:grid-cols-3">
        <div>{{ __('israeli-payout-history.date') }}<strong class="block">{{ $entry['date']->format('d.m.Y H:i') }}</strong></div>
        <div>{{ __('israeli-payout-history.source') }}<strong class="block">Israeli</strong></div>
        <div>{{ __('israeli-payout-history.status') }}<strong class="block">{{ __('israeli-payout-history.'.($entry['status'] === 'paid' ? 'complete' : $entry['status'])) }}</strong></div>
        @foreach(['accrued', 'covered', 'remaining'] as $field)
            @if($entry[$field] !== null)<div>{{ __('israeli-payout-history.'.$field) }}<strong class="block tabular-nums">{{ \App\Support\Currency::format($entry[$field], $entry['basis_currency']) }}</strong></div>@endif
        @endforeach
    </div>
    <div class="space-y-2 rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
        <h4 class="font-semibold">{{ __('israeli-payout-history.total_paid') }}:
            @foreach(collect($entry['payments'])->groupBy('currency') as $currency => $payments)<span class="tabular-nums">{{ \App\Support\Currency::format($payments->sum('amount'), $currency) }}</span>@endforeach
            @if($entry['payments'] === [])0.00 ₾@endif
        </h4>
        @foreach($entry['payments'] as $payment)
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                <span>{{ __('israeli-payout-history.currency') }}: {{ $payment['currency'] }}</span>
                <span>{{ __('israeli-payout-history.method') }}: {{ $payment['method'] ? __('israeli-payout-history.'.($payment['method'] === 'bank_transfer' ? 'bank' : $payment['method'])) : __('israeli-payout-history.unknown') }}</span>
                <strong class="tabular-nums">{{ \App\Support\Currency::format($payment['amount'], $payment['currency']) }}</strong>
                @if($payment['funding'])<span>{{ __('israeli-payout-history.funding') }}: {{ __('israeli-payout-history.'.$payment['funding']) }}</span>@endif
                @if($payment['rate'] !== null)<span>{{ __('israeli-payout-history.rate') }}: {{ rtrim(rtrim((string) $payment['rate'], '0'), '.') }}</span>@endif
                @if(isset($payment['equivalent']))<span>{{ __('israeli-payout-history.equivalent') }}: {{ \App\Support\Currency::format($payment['equivalent'], 'GEL') }}</span>@endif
            </div>
        @endforeach
        @if($entry['deducted'] > 0)<div>{{ __('israeli-payout-history.deducted') }}: {{ \App\Support\Currency::format($entry['deducted'], 'GEL') }}</div>@endif
        @foreach($entry['carry'] as $field => $amount)<div>{{ __('israeli-payout-history.'.$field) }}: {{ number_format($amount, 2) }} USD</div>@endforeach
    </div>
    @if($entry['composition'] === 'unavailable')
        <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ __('israeli-payout-history.unavailable') }}</p>
    @else
        @if($entry['composition'] === 'linked')<p class="text-xs text-gray-500">{{ __('israeli-payout-history.linked') }}</p>@endif
        <p class="text-xs text-gray-500">{{ __('israeli-payout-history.basis_note') }} ({{ $entry['basis_currency'] }})</p>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[1050px] text-left text-xs">
                <thead class="bg-gray-50 text-gray-500 dark:bg-white/5"><tr>
                    @foreach(['patient', 'work_date', 'service', 'quantity', 'base', 'percentage', 'salary', 'paid', 'deducted', 'row_remaining'] as $field)<th class="px-2 py-2">{{ __('israeli-payout-history.'.$field) }}</th>@endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach($entry['items'] as $item)
                        <tr class="align-top even:bg-gray-50 dark:even:bg-white/5">
                            <td class="px-2 py-2">{{ $item['patient'] }}</td>
                            <td class="whitespace-nowrap px-2 py-2">{{ $item['work_date'] ? \Carbon\Carbon::parse($item['work_date'])->format('d.m.Y') : '—' }}</td>
                            <td class="px-2 py-2">{{ $item['service'] }}@if($item['unit_rate'] !== null)<div class="text-gray-500">{{ __('israeli-payout-history.unit_rate') }}: {{ \App\Support\Currency::format($item['unit_rate'], $entry['basis_currency']) }}</div>@endif</td>
                            <td class="px-2 py-2 tabular-nums">{{ $item['quantity'] ?? '—' }}</td>
                            <td class="px-2 py-2 tabular-nums">{{ $item['base'] !== null ? \App\Support\Currency::format($item['base'], $entry['basis_currency']) : '—' }}</td>
                            <td class="px-2 py-2 tabular-nums">{{ $item['percentage'] !== null ? number_format($item['percentage'], 2).'%' : '—' }}</td>
                            @foreach(['accrued', 'paid', 'deducted', 'remaining'] as $field)<td class="whitespace-nowrap px-2 py-2 tabular-nums">{{ \App\Support\Currency::format($item[$field], $entry['basis_currency']) }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-semibold dark:bg-white/5"><tr><td colspan="7" class="px-2 py-2">{{ __('israeli-payout-history.total_paid') }}</td><td class="px-2 py-2 tabular-nums">{{ \App\Support\Currency::format(collect($entry['items'])->sum('paid'), $entry['basis_currency']) }}</td><td class="px-2 py-2 tabular-nums">{{ \App\Support\Currency::format(collect($entry['items'])->sum('deducted'), $entry['basis_currency']) }}</td><td></td></tr></tfoot>
            </table>
        </div>
    @endif
</div>
