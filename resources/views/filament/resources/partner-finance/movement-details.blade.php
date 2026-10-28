@php
    use App\Models\PartnerFinanceTransaction;
    use App\Support\Currency;
@endphp

<div class="space-y-4">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ $from ? \Carbon\Carbon::parse($from)->format('d.m.Y') : '—' }} — {{ $until ? \Carbon\Carbon::parse($until)->format('d.m.Y') : '—' }}
    </div>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 font-medium">თარიღი</th>
                    <th class="px-3 py-2 font-medium">თანხა</th>
                    <th class="px-3 py-2 font-medium">ვალუტა</th>
                    <th class="px-3 py-2 font-medium">შენიშვნა</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($transactions as $transaction)
                    <tr wire:key="israeli-movement-detail-{{ $transaction->id }}">
                        <td class="whitespace-nowrap px-3 py-2">{{ $transaction->transacted_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            @if ($transaction->type === PartnerFinanceTransaction::TYPE_EXCHANGE)
                                {{ Currency::format($transaction->from_amount, $transaction->from_currency) }} → {{ Currency::format($transaction->to_amount, $transaction->to_currency) }}
                            @else
                                {{ Currency::format($transaction->amount, $transaction->currency) }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $transaction->type === PartnerFinanceTransaction::TYPE_EXCHANGE ? $transaction->from_currency.' → '.$transaction->to_currency : $transaction->currency }}</td>
                        <td class="px-3 py-2">{{ collect([$transaction->recipient, $transaction->notes])->filter()->implode(' · ') ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-4 text-gray-500">ჩანაწერები არ მოიძებნა</td></tr>
                @endforelse
            </tbody>
            <tfoot class="border-t border-gray-200 font-semibold dark:border-white/10">
                <tr><td class="px-3 py-2">სულ</td><td colspan="3" class="px-3 py-2">{{ $movement['display'] }}</td></tr>
            </tfoot>
        </table>
    </div>
    {{ $transactions->links() }}
</div>
