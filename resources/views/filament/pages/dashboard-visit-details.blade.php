@php
    $notes = collect([
        'ჩივილი' => $visit->complaint,
        'დიაგნოზი' => $visit->diagnosis,
        'მკურნალობის ჩანაწერი' => $visit->treatment_notes,
        'ექიმის ჩანაწერი' => $visit->doctor_notes,
        'კომენტარი' => $visit->comment,
        'შენიშვნა' => $visit->notes,
    ])->filter(fn ($value) => filled($value));
    $visitTime = filled($visit->getAttribute('visit_time'))
        ? substr((string) $visit->getAttribute('visit_time'), 0, 5)
        : null;
@endphp

<div class="renome-visit-details space-y-4">
    <div class="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3 dark:border-white/10 dark:bg-gray-900">
        <div>
            <div class="text-xs text-gray-500">თარიღი / დრო</div>
            <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                {{ $visit->visit_date?->format('d.m.Y') ?? '—' }}{{ $visitTime ? ' · '.$visitTime : '' }}
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-500">პაციენტი</div>
            <div class="renome-patient-name mt-1 text-sm">{{ $visit->patient?->full_name ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">ექიმი</div>
            <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $visit->doctor?->full_name ?? '—' }}</div>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="text-left">მომსახურება / მანიპულაცია</th>
                    <th class="text-left">კბილი</th>
                    <th class="text-right">რაოდ.</th>
                    <th class="text-right">ერთ. ფასი</th>
                    <th class="text-right">ჯამი</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($visit->treatmentCaseItems as $item)
                    <tr>
                        <td>
                            <span class="renome-treatment-service">{{ $item->display_name }}</span>
                            @if (filled($item->comment))
                                <div class="mt-1 text-xs text-gray-500">{{ $item->comment }}</div>
                            @endif
                        </td>
                        <td class="text-gray-600">{{ $item->teeth ?: '—' }}</td>
                        <td class="text-right tabular-nums">{{ $item->quantity }}</td>
                        <td class="text-right tabular-nums">{{ \App\Support\Currency::format($item->unit_price, $item->currency ?: $visit->currency) }}</td>
                        <td class="text-right font-medium tabular-nums">{{ \App\Support\Currency::format($item->manipulation_total, $item->currency ?: $visit->currency) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-gray-500">მანიპულაციები არ არის</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500">სრული თანხა</div>
            <div class="mt-1 text-sm font-semibold tabular-nums">{{ \App\Support\Currency::format($visit->total_price, $visit->currency) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500">ფაქტობრივი გადახდები</div>
            <div class="mt-1 text-sm font-medium text-emerald-600">
                {!! \App\Support\PaymentPresentation::methodAmountsHtml($visit->payments, $visit->currency) !!}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs text-gray-500">გადასახდელი</div>
            <div @class([
                'mt-1 text-sm font-semibold tabular-nums',
                'text-rose-600' => (float) $visit->remaining_amount > 0,
                'text-gray-500' => (float) $visit->remaining_amount <= 0,
            ])>{{ \App\Support\Currency::format($visit->remaining_amount, $visit->currency) }}</div>
        </div>
    </div>

    @if ($visit->payments->isNotEmpty())
        <div>
            <div class="mb-2 text-sm font-medium text-gray-950 dark:text-white">გადახდები</div>
            <div class="space-y-2">
                @foreach ($visit->payments as $payment)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                        <div class="text-gray-500">{{ $payment->payment_date?->format('d.m.Y') ?? '—' }}</div>
                        <div class="font-medium text-emerald-600">{!! \App\Support\PaymentPresentation::methodAmountsHtml([$payment], $payment->currency) !!}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($notes->isNotEmpty())
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($notes as $label => $value)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                    <div class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
