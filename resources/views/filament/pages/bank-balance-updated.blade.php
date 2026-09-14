@if($balance->balance_as_of)
    @php($balanceDate = \Carbon\Carbon::parse($balance->balance_as_of))
    <p class="mt-1 text-xs font-normal text-gray-500">
        {{ $balance->balance_origin === 'opening' ? __('finance-overview.opening') : $balance->bank.' '.__('bank.statement') }} · {{ __('bank.updated') }} {{ $balanceDate->format('d.m.Y H:i') }}
        @if($balanceDate->isBefore(today()))
            @php($balanceAge = (int) $balanceDate->startOfDay()->diffInDays(today()))
            <span class="block">{{ __($balanceAge === 1 ? 'bank.one_day_old' : 'bank.days_old', ['days' => $balanceAge]) }}</span>
        @endif
    </p>
@else
    <p class="mt-1 text-xs font-normal text-gray-500">{{ __('bank.unknown_balance') }}</p>
@endif
