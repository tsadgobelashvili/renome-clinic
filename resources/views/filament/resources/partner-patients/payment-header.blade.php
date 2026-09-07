<div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
    <h3 class="text-sm font-semibold">{{ app()->getLocale() === 'ka' ? 'გადახდების ისტორია' : 'Payment History' }}</h3>
    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm tabular-nums">
        <span><span class="text-gray-500">{{ app()->getLocale() === 'ka' ? 'სულ GEL' : 'Total GEL' }}:</span> <strong>{{ number_format($totals['GEL'] ?? 0, 2) }} ₾</strong></span>
        <span><span class="text-gray-500">{{ app()->getLocale() === 'ka' ? 'სულ USD' : 'Total USD' }}:</span> <strong>${{ number_format($totals['USD'] ?? 0, 2) }}</strong></span>
    </div>
</div>
