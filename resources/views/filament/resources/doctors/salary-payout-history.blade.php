@if($settlement->uses_allocations)
    @php
        $paid = (float) ($settlement->paid_gel ?? $settlement->payouts->sum('total_gel'));
        $remaining = max(0, round((float) $settlement->salary_total - $paid, 2));
    @endphp
    <div class="space-y-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
        <div class="flex flex-wrap justify-between gap-2">
            <span>{{ $settlement->period_start->format('d.m.Y') }} — {{ $settlement->period_end->format('d.m.Y') }}</span>
            <strong>{{ __('salary-payout.salary') }}: {{ \App\Support\Currency::format($settlement->salary_total, 'GEL') }}</strong>
        </div>
        <div class="flex flex-wrap justify-between gap-2 text-xs"><span>{{ __('salary-payout.paid') }}: {{ \App\Support\Currency::format($paid, 'GEL') }}</span><strong>{{ __('salary-payout.remaining') }}: {{ \App\Support\Currency::format($remaining, 'GEL') }}</strong></div>
        <details class="text-xs"><summary class="cursor-pointer">{{ __('salary-payout.payments') }}</summary>
            @foreach($settlement->payouts as $payout)
                <div class="mt-2 text-gray-500">{{ $payout->created_at->format('d.m.Y H:i') }}</div>
                @foreach($payout->allocations as $allocation)
                    <div class="mt-1">{{ \App\Support\Currency::format($allocation->amount, $allocation->currency) }} · {{ __('salaries.'.$allocation->source) }} @if($allocation->currency === 'USD') · {{ __('salary-payout.rate') }} {{ rtrim(rtrim($allocation->exchange_rate, '0'), '.') }} @endif · {{ \App\Support\Currency::format($allocation->gel_equivalent, 'GEL') }}</div>
                @endforeach
            @endforeach
        </details>
        @if($settlement->relationLoaded('items'))
            <details class="text-xs"><summary class="cursor-pointer">{{ __('salary-payout.work') }}</summary>
                @foreach($settlement->items as $item)
                    <div class="mt-2 flex flex-wrap justify-between gap-2">
                        <span>{{ $item->labMainWork?->labCase?->case_date?->format('d.m.Y') }} · {{ $item->labMainWork?->labCase?->patient?->lab_name }} · {{ strtoupper($item->labMainWork?->material ?? '') }} ×{{ $item->quantity_snapshot }}</span>
                        <strong>{{ \App\Support\Currency::format($item->doctor_share_snapshot, 'GEL') }}</strong>
                    </div>
                @endforeach
            </details>
        @endif
        @if(($showPayButton ?? true) && $remaining > 0)
            <x-filament::button size="xs" wire:click="mountAction('payIsraeliSalary', { settlement: {{ $settlement->id }} })">{{ __('salary-payout.pay_remaining') }}</x-filament::button>
        @endif
    </div>
@endif
