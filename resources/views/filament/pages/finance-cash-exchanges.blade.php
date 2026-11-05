<details class="rounded-lg border border-gray-200 dark:border-white/10" data-cash-exchange-section>
    <summary class="cursor-pointer px-3 py-2 text-sm font-medium">{{ __('finance-overview.exchange_operations') }}</summary>
    <div class="space-y-2 px-3 pb-3">
        <p class="text-xs text-gray-500">{{ __('finance-overview.exchange_link_limit') }}</p>
        <div class="max-h-80 overflow-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 text-gray-500 dark:bg-white/5"><tr>
                    @foreach(['date', 'exchange_pair', 'exchange_rate', 'exchange_deposited', 'exchange_retained'] as $field)<th class="px-2 py-2 font-medium">{{ __('finance-overview.'.$field) }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @forelse($exchangeOperations ?? [] as $exchange)
                        <tr wire:key="cash-exchange-{{ $exchange->id }}" class="align-top even:bg-gray-50 dark:even:bg-white/5">
                            <td class="whitespace-nowrap px-2 py-2">{{ \Carbon\Carbon::parse($exchange->transacted_at)->format('d.m.Y H:i') }}<span class="block text-gray-500">{{ __('finance-overview.'.$exchange->source) }}</span></td>
                            <td class="px-2 py-2 tabular-nums">
                                <span class="whitespace-nowrap">{{ number_format($exchange->from_amount, 2) }} {{ $exchange->from_currency }} → {{ number_format($exchange->to_amount, 2) }} {{ $exchange->to_currency }}</span>
                                <details class="mt-1 text-gray-500">
                                    <summary class="cursor-pointer">{{ __('finance-overview.more') }} · #{{ $exchange->id }}</summary>
                                    <p>{{ \App\Enums\PartnerAccount::tryFrom($exchange->from_account)?->label() }} → {{ \App\Enums\PartnerAccount::tryFrom($exchange->to_account)?->label() }}</p>
                                    @if($exchange->to_account === 'cash')<p>{{ __('finance-overview.exchange_cash_received') }}: {{ number_format($exchange->cash_received, 2) }} {{ $exchange->to_currency }}</p>@endif
                                    @if($exchange->notes)<p class="max-w-80 whitespace-pre-line">{{ $exchange->notes }}</p>@endif
                                </details>
                            </td>
                            <td class="px-2 py-2 tabular-nums">{{ rtrim(rtrim(number_format($exchange->exchange_rate, 6, '.', ''), '0'), '.') }}</td>
                            <td class="px-2 py-2 tabular-nums">{{ $exchange->bank_amount === null ? __('finance-overview.exchange_unknown') : number_format($exchange->bank_amount, 2).' '.$exchange->to_currency }}</td>
                            <td class="px-2 py-2 tabular-nums">{{ $exchange->retained_amount === null ? __('finance-overview.exchange_unknown') : number_format($exchange->retained_amount, 2).' '.$exchange->to_currency }}</td>
                        </tr>
                    @empty<tr><td colspan="5" class="px-2 py-3 text-gray-500">{{ __('bank.empty') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @if($exchangeOperations)<div>{{ $exchangeOperations->links() }}</div>@endif
    </div>
</details>
