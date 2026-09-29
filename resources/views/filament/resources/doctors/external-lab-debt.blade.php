@if($charges->isNotEmpty())
<div class="space-y-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
    <details><summary class="cursor-pointer">გარე სამუშაოები · დარჩენილი: {{ \App\Support\Currency::format($charges->sum(fn ($charge) => $charge->remaining()), 'GEL') }}</summary>
        @foreach($charges as $charge)
            <div class="mt-2">{{ $charge->work->labCase->case_date?->format('d.m.Y') }} · {{ $charge->work->labCase->patient_display }} · {{ strtoupper($charge->work->material) }} × {{ $charge->work->quantity }} · {{ \App\Support\Currency::format($charge->unit_rate, 'GEL') }}/ერთეული — დარჩენილი {{ \App\Support\Currency::format($charge->remaining(), 'GEL') }}</div>
        @endforeach
    </details>
    <p>გასაცემი: <strong>{{ \App\Support\Currency::format($gross, 'GEL') }}</strong></p>
    <p>დასაქვითი: <strong>{{ \App\Support\Currency::format($deduction, 'GEL') }}</strong></p>
    <p>სხვაობა: <strong>{{ \App\Support\Currency::format($gross - $deduction, 'GEL') }}</strong></p>
</div>
@endif
