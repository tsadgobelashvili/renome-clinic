<div class="mt-1 text-xs font-normal text-gray-500">
    @if($bogBalance)
        <p>{{ __('finance-overview.bank_fetched') }}: {{ \Carbon\Carbon::parse($bogBalance['fetched_at'])->format('d.m.Y H:i:s') }}</p>
    @elseif($bogBalanceFailed)
        <p>{{ __('finance-overview.bank_unavailable') }}</p>
    @else
        <p>{{ __('bog-transactions.balance_loading') }}</p>
    @endif
    <p>{{ __('finance-overview.bank_synced') }}: {{ $lastBogSyncAt ?? '—' }}</p>
</div>
