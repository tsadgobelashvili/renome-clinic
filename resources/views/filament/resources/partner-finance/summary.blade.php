@php
    use App\Enums\PartnerAccount;
    use App\Support\Currency;

    $received = $summary->receivedTotals();
    $expenses = $summary->expenseTotals();
    $balances = $summary->accountBalances();
@endphp

<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    @foreach (['GEL', 'USD'] as $currency)
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">მიღებულია {{ $currency }}</div>
            <div class="mt-1 whitespace-nowrap text-xl font-semibold text-success-600 dark:text-success-400">
                {{ Currency::format($received[$currency] ?? 0, $currency) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                ხარჯი: {{ Currency::format($expenses[$currency] ?? 0, $currency) }}
            </div>
        </div>
    @endforeach

    @foreach (PartnerAccount::cases() as $account)
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->label() }}</div>
            <div class="mt-1 space-y-1 text-sm font-semibold">
                @foreach (['GEL', 'USD'] as $currency)
                    <div class="whitespace-nowrap">{{ Currency::format($balances[$account->value][$currency] ?? 0, $currency) }}</div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
