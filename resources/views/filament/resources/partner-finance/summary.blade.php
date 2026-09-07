@php
    use App\Models\PartnerFinanceTransaction;
    use App\Support\Currency;

    $overview = $this->overview();
@endphp

<div class="space-y-3 pb-4">
    <div class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
        <label class="min-w-32 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>პერიოდი</span>
            <select wire:model.live="period" class="fi-select-input w-full rounded-lg">
                <option value="7_days">7 დღე</option>
                <option value="1_month">1 თვე</option>
                <option value="3_months">3 თვე</option>
                <option value="1_year">1 წელი</option>
                <option value="custom">საკუთარი</option>
            </select>
        </label>
        <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>დან</span><input type="date" lang="ka" wire:model.live="dateFrom" class="fi-input rounded-lg"></label>
        <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>მდე</span><input type="date" lang="ka" wire:model.live="dateUntil" class="fi-input rounded-lg"></label>
        <label class="min-w-40 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>მოძრაობის ტიპი</span>
            <select wire:model.live="movementType" class="fi-select-input w-full rounded-lg">
                <option value="">ყველა</option>
                <option value="payment">შემოსავალი</option>
                <option value="{{ PartnerFinanceTransaction::TYPE_EXPENSE }}">ხარჯი</option>
                <option value="{{ PartnerFinanceTransaction::TYPE_EXCHANGE }}">გაცვლა</option>
                <option value="{{ PartnerFinanceTransaction::TYPE_TRANSFER }}">ტრანსფერი</option>
                <option value="{{ PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL }}">მფლობელის გატანა</option>
            </select>
        </label>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['label' => 'მიმდინარე ქეში', 'values' => $overview['cash'], 'class' => 'border-indigo-200 bg-indigo-50/50 text-indigo-700 dark:border-indigo-400/20 dark:bg-indigo-400/5 dark:text-indigo-300'],
            ['label' => 'შემოსავალი', 'values' => $overview['income'], 'class' => 'border-success-200 bg-success-50/50 text-success-700 dark:border-success-400/20 dark:bg-success-400/5 dark:text-success-300'],
            ['label' => 'ხარჯი', 'values' => $overview['expense'], 'class' => 'border-danger-200 bg-danger-50/50 text-danger-700 dark:border-danger-400/20 dark:bg-danger-400/5 dark:text-danger-300'],
        ] as $card)
            <section class="rounded-xl border p-3.5 {{ $card['class'] }}">
                <div class="text-xs font-medium">{{ $card['label'] }}</div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xl font-semibold">
                    @if ($card['label'] === 'ხარჯი' && ($overview['expense_funding']['exchanged_usd'] ?? 0) > 0.005)
                        <span class="whitespace-nowrap">{{ Currency::format($overview['expense_funding']['exchanged_usd'], 'USD') }} → {{ Currency::format($overview['expense_funding']['funded_gel'], 'GEL') }}</span>
                    @else
                        <span class="whitespace-nowrap">{{ Currency::format($card['values']['GEL'] ?? 0, 'GEL') }}</span>
                        <span class="whitespace-nowrap">{{ Currency::format($card['values']['USD'] ?? 0, 'USD') }}</span>
                    @endif
                </div>
                @if ($card['label'] === 'ხარჯი' && ($overview['expense_funding']['exchanged_usd'] ?? 0) > 0.005)
                    <div class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <div>გადახურდავებული USD-დან</div>
                        @if (($overview['expense_funding']['direct_gel'] ?? 0) > 0.005)
                            <div><span class="font-medium">პირდაპირი GEL:</span> {{ Currency::format($overview['expense_funding']['direct_gel'], 'GEL') }}</div>
                        @endif
                        @if (($card['values']['USD'] ?? 0) > 0.005)
                            <div><span class="font-medium">პირდაპირი USD:</span> {{ Currency::format($card['values']['USD'], 'USD') }}</div>
                        @endif
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    @if ($overview['movements'] !== [])
        <section class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">ფულის მოძრაობა</div>
            <div class="mt-2 grid gap-3 md:grid-cols-3">
                @foreach ($overview['movements'] as $dateGroup)
                    <div>
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $dateGroup['date'] }}</div>
                        <div class="mt-1 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                            @foreach ($dateGroup['movements'] as $movement)
                                <div><span class="font-medium">{{ $movement['label'] }}:</span> {{ $movement['display'] }}</div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
