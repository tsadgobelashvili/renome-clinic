@php
    $methodLabels = ['cash' => 'ნაღდი', 'card' => 'ბარათი', 'bank_transfer' => 'გადარიცხვა'];
    $visit = $transaction->visit;
    $items = $transaction->type === 'product_sale'
        ? ($transaction->productSale?->items ?? collect())
        : ($visit?->treatmentCaseItems ?? collect());
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-xs text-gray-500">პაციენტი</dt><dd class="font-medium">{{ $transaction->patient?->full_name ?? '—' }}</dd></div>
        <div><dt class="text-xs text-gray-500">ექიმი</dt><dd class="font-medium">{{ $visit?->doctor?->full_name ?? '—' }}</dd></div>
        <div><dt class="text-xs text-gray-500">Visit</dt><dd class="font-medium">{{ $transaction->visit_id ? '#'.$transaction->visit_id : '—' }}</dd></div>
        <div><dt class="text-xs text-gray-500">გადახდა</dt><dd class="font-medium">{{ $methodLabels[$transaction->payment_method] ?? ($transaction->payment_method ?: '—') }}</dd></div>
    </dl>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[36rem] text-xs">
            <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/5">
                <tr><th class="p-2">სერვისი / პროდუქტი</th><th class="p-2 text-right">რაოდენობა</th><th class="p-2 text-right">ერთეულის ფასი</th><th class="p-2 text-right">ჯამი</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($items as $item)
                    @php
                        $isProduct = $transaction->type === 'product_sale';
                        $name = $isProduct ? ($item->product?->name ?? 'პროდუქტი') : ($item->treatmentCase?->name ?? $item->custom_service_name ?? 'სერვისი');
                        $currency = $isProduct ? 'GEL' : ($item->currency ?? $visit?->currency ?? 'GEL');
                        $lineTotal = $isProduct ? $item->line_total : ((int) ($item->quantity ?: 1) * (float) $item->unit_price);
                    @endphp
                    <tr>
                        <td class="p-2">{{ $name }}</td>
                        <td class="p-2 text-right">×{{ $item->quantity ?: 1 }}</td>
                        <td class="whitespace-nowrap p-2 text-right">{{ \App\Support\Currency::format($item->unit_price, $currency) }}</td>
                        <td class="whitespace-nowrap p-2 text-right font-medium">{{ \App\Support\Currency::format($lineTotal, $currency) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-center text-gray-500">დეტალები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap justify-end gap-x-5 gap-y-1 border-t border-gray-200 pt-3 dark:border-white/10">
        <span class="text-gray-500">თანხა</span>
        <strong>{{ \App\Support\Currency::format($transaction->amount, $transaction->currency) }}</strong>
        <span>{{ $transaction->currency }}</span>
        <span>{{ $methodLabels[$transaction->payment_method] ?? ($transaction->payment_method ?: '—') }}</span>
    </div>
</div>
