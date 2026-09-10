<span class="text-right tabular-nums">{{ number_format($row->patients) }}</span>
<span class="text-right tabular-nums">{{ number_format($row->visits) }}</span>
<span class="text-right tabular-nums">{{ number_format($row->quantity) }}</span>
<span class="text-right tabular-nums">{{ \App\Support\Currency::format($row->original_value, $currency) }}</span>
<span class="text-right font-semibold tabular-nums text-primary-700 dark:text-primary-300">
    @include('filament.pages.partials.discount-statistics-salary', ['row' => $row])
</span>
