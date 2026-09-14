@php($status = $remaining < 0 ? 'advance' : ($remaining > 0 ? 'remaining' : 'complete'))
<div class="renome-salary-allocation-summary flex flex-wrap items-center gap-x-5 gap-y-1 py-1 text-xs" data-allocation-status="{{ $status }}">
    @foreach(['salary' => $salary, 'paid' => $paid, 'allocated' => $allocated] as $key => $amount)
        @continue($key === 'paid' && $amount <= 0)
        <span class="whitespace-nowrap">{{ __('salary-payout.'.$key) }}: <strong class="tabular-nums">{{ \App\Support\Currency::format($amount, 'GEL') }}</strong></span>
    @endforeach
    <span @class([
        'whitespace-nowrap font-semibold',
        'text-orange-600 dark:text-orange-400' => $status === 'advance',
        'text-gray-900 dark:text-gray-100' => $status === 'remaining',
        'text-success-600 dark:text-success-400' => $status === 'complete',
    ])>{{ __('salary-payout.'.($status === 'advance' ? 'advance' : 'remaining')) }}: <strong class="tabular-nums">{{ \App\Support\Currency::format(abs($remaining), 'GEL') }}</strong></span>
    @error('allocations')<p class="w-full text-danger-600">{{ $message }}</p>@enderror
    @error('transaction_date')<p class="w-full text-danger-600">{{ $message }}</p>@enderror
</div>
