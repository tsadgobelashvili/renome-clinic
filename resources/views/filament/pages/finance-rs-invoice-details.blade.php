<div class="space-y-3 text-sm" style="max-height:65vh;overflow:auto;overflow-wrap:anywhere">
    <p class="font-medium">ბანკი #{{ $bank->id }} · {{ $bank->transaction_date->format('d.m.Y') }} · {{ $bank->counterparty_name }} · {{ number_format($bank->amount, 2) }} {{ $bank->currency }}</p>
    <p>{{ $expense->dimension_name ?? 'დასაზუსტებელია' }} → {{ $expense->dimension_subname ?? 'დასაზუსტებელია' }} · {{ number_format($allocatedTetri / 100, 2) }} GEL</p>
    @if($bank->reference)<p class="text-xs text-gray-500">ბანკის რეფერენსი: {{ $bank->reference }}</p>@endif
    <p class="text-xs text-gray-500">ზედნადების სრული ღირებულება და ბანკიდან მიბმული გადახდა ნაჩვენებია ცალ-ცალკე. მონიშნული პროდუქტები არჩეულ ხარჯის ჯგუფში მონაწილეობს.</p>
    @foreach($documents as $document)
        <details class="rounded-lg border border-gray-200 dark:border-white/10" wire:key="rs-detail-invoice-{{ $document['id'] }}">
            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-3 py-2">
                <span class="min-w-0 flex-1"><strong>{{ $document['number'] ? '№'.$document['number'] : 'დოკუმენტი #'.$document['id'] }}</strong> · {{ $document['date']->format('d.m.Y') }}<span class="block text-xs text-gray-500">{{ $document['supplier'] }}</span></span>
                <span class="text-right text-xs tabular-nums">სრული ღირებულება: {{ number_format($document['total_tetri'] / 100, 2) }} GEL<br>ბანკიდან მიბმული: {{ number_format($document['bank_tetri'] / 100, 2) }} GEL<br><strong>ამ ჯგუფზე: {{ number_format($document['group_tetri'] / 100, 2) }} GEL</strong></span>
                <span class="font-medium text-primary-600">ნახვა</span>
            </summary>
            <div class="overflow-x-auto border-t border-gray-200 p-2 dark:border-white/10">
                <table class="w-full text-left text-xs">
                    <thead><tr>@foreach(['პროდუქტი', 'რაოდენობა', 'ერთეული', 'ერთეულის ფასი', 'თანხა', 'პროდუქტის ჯგუფი', 'მიმართულება'] as $label)<th class="px-2 py-1 font-medium">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach($document['items'] as $item)
                            <tr wire:key="rs-detail-item-{{ $item->id }}" @class(['border-t border-gray-100 dark:border-white/5', 'bg-primary-50 dark:bg-primary-500/10' => $item->contributes])>
                                <td class="px-2 py-1.5">{{ $item->item_name ?: $item->catalog_name ?: $item->legacy_name ?: 'პროდუქტი #'.$item->id }}<span class="block text-gray-500">{{ $item->contributes ? 'არჩეული ჯგუფი' : 'სხვა ჯგუფი' }}</span></td>
                                <td class="px-2 py-1.5 tabular-nums">{{ str_contains((string) $item->quantity, '.') ? rtrim(rtrim((string) $item->quantity, '0'), '.') : $item->quantity }}</td>
                                <td class="px-2 py-1.5">{{ $item->unit ?: '—' }}</td>
                                <td class="px-2 py-1.5 tabular-nums">{{ $item->unit_price === null ? '—' : number_format($item->unit_price, 2) }}</td>
                                <td class="px-2 py-1.5 tabular-nums">{{ number_format($item->line_total, 2) }}</td>
                                <td class="px-2 py-1.5">{{ $item->group_name ?? 'დასაზუსტებელია' }}</td>
                                <td class="px-2 py-1.5">{{ $item->direction_name ?? 'დასაზუსტებელია' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="7" class="px-2 pt-2 text-right font-medium">ზედნადების სრული ჯამი: {{ number_format($document['total_tetri'] / 100, 2) }} GEL</td></tr></tfoot>
                </table>
            </div>
        </details>
    @endforeach
    <div class="flex flex-wrap justify-between gap-2 border-t border-gray-200 pt-2 text-xs tabular-nums dark:border-white/10">
        <span>{{ $documents->count() }} ზედნადები · სრული ჯამი: {{ number_format($invoiceTetri / 100, 2) }} GEL</span>
        <span>ბანკიდან მიბმული: {{ number_format($bankAllocatedTetri / 100, 2) }} GEL</span>
        <strong>ამ ჯგუფზე განაწილებული: {{ number_format($allocatedTetri / 100, 2) }} GEL</strong>
    </div>
</div>
