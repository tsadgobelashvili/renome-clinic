@php
    $advanceAmount = $carry['advance_created_gel'] ?? max(0, -$remaining);
    $status = $advanceAmount > 0 ? 'advance' : ($remaining > 0 ? 'remaining' : 'complete');
@endphp
<div class="renome-salary-allocation-summary flex flex-wrap items-center gap-x-5 gap-y-1 py-1 text-xs" data-allocation-status="{{ $status }}">
    @foreach(['salary' => $salary, 'paid' => $paid, 'deducted' => ($deducted ?? 0), 'allocated' => $allocated] as $key => $amount)
        @continue(in_array($key, ['paid', 'deducted']) && $amount <= 0)
        <span class="whitespace-nowrap">{{ $key === 'deducted' ? 'გარე სამუშაოების დაქვითვა' : __('salary-payout.'.match($key) { 'allocated' => 'actual_paid', 'paid' => 'covered', default => $key }) }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($amount, 'GEL') }}</strong></span>
    @endforeach
    @if(isset($carry))
        @foreach($carry['opening'] as $currency => $amount)
            @if($amount > 0)<span>{{ __('salary-payout.previous_advance') }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($amount, $currency) }}</strong></span>@endif
        @endforeach
        @if($paid > 0)<span>{{ __('salary-payout.outstanding_before') }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($carry['covered_gel'] + $carry['remaining_gel'], 'GEL') }}</strong></span>@endif
        @if($carry['advance_applied_gel'] > 0)<span>{{ __('salary-payout.advance_applied') }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($carry['advance_applied_gel'], 'GEL') }}</strong></span>@endif
        @foreach($carry['credits'] as $currency => $amount)
            @if($amount > 0)<span>{{ __('salary-payout.new_advance') }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($amount, $currency) }}</strong></span>@endif
        @endforeach
        @if($carry['advance_created_gel'] > 0)<span class="text-gray-500">{{ __('salary-payout.credit_help') }}</span>@endif
    @endif
    <span @class([
        'whitespace-nowrap font-semibold',
        'text-orange-600 dark:text-orange-400' => $status === 'advance',
        'text-gray-900 dark:text-gray-100' => $status === 'remaining',
        'text-success-600 dark:text-success-400' => $status === 'complete',
    ])>{{ __('salary-payout.'.($status === 'advance' ? 'advance' : 'remaining')) }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($status === 'advance' ? $advanceAmount : $remaining, 'GEL') }}</strong></span>
    @error('allocations')<p class="w-full text-danger-600">{{ $message }}</p>@enderror
    @error('transaction_date')<p class="w-full text-danger-600">{{ $message }}</p>@enderror
</div>
