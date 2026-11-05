<div class="flex flex-wrap justify-end gap-x-6 gap-y-2 text-sm font-semibold tabular-nums">
    @foreach($salaryTotals as $currency => $amounts)
        <span>{{ __('salaries.fixed_total_net') }}: {{ \App\Support\Currency::format($amounts['net'] / 100, $currency) }}</span>
        <span>{{ __('salaries.fixed_total_cost') }}: {{ \App\Support\Currency::format($amounts['cost'] / 100, $currency) }}</span>
    @endforeach
</div>
