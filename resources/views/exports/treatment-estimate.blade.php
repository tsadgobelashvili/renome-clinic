@php
    $language ??= 'ka';
    $labels ??= \App\Support\TreatmentPlanDocument::labels($language);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $labels['title'] }}</title>
    <style>
        @page { margin: 100px 36px 48px; }
        body { font-family: "{{ $exportFontFamily }}", sans-serif; color: #222; font-size: 12px; }
        .clinic-header { position: fixed; top: -76px; left: 0; right: 0; border-bottom: 1px solid #ccc; padding-bottom: 8px; }
        .clinic-name { font-size: 14px; font-weight: bold; }
        .clinic-address { font-size: 10px; color: #555; margin-top: 3px; }
        .clinic-contact { font-size: 9px; color: #555; margin-top: 3px; }
        .clinic-footer { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; font-size: 9px; color: #555; }
        h2 { text-align: center; margin: 0; }
        h2 { margin-top: 6px; margin-bottom: 26px; font-size: 16px; }
        .meta { margin-bottom: 20px; line-height: 1.8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .number { text-align: right; white-space: nowrap; }
        .total { margin-top: 18px; text-align: right; font-weight: bold; font-size: 15px; }
        .details { margin-top: 22px; line-height: 1.7; }
        .treatment-option {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .treatment-option + .treatment-option { margin-top: 22px; }
        .treatment-option > h3,
        .treatment-option h4 {
            break-after: avoid;
            page-break-after: avoid;
        }
    </style>
</head>
<body>
    <div class="clinic-header">
        <div class="clinic-name">{{ $labels['clinic'] }}</div>
        <div class="clinic-address">{{ $labels['address'] }}</div>
        <div class="clinic-contact">{{ \App\Support\TreatmentPlanDocument::CONTACT }}</div>
    </div>
    <div class="clinic-footer">{{ \App\Support\TreatmentPlanDocument::CONTACT }}</div>
    <h2>{{ $labels['title'] }}</h2>

    <div class="meta">
        <div><strong>{{ $labels['patient'] }}:</strong> {{ $estimate->patient?->full_name ?? '—' }}</div>
        <div><strong>{{ $labels['date'] }}:</strong> {{ $estimate->estimate_date?->format('d.m.Y') ?? '—' }}</div>
        @if ($estimate->doctor)
            <div><strong>{{ $labels['doctor'] }}:</strong> {{ $estimate->doctor->full_name }}</div>
        @endif
    </div>

    @foreach ($estimate->options as $index => $option)
        <div class="treatment-option">
            @if ($estimate->options->count() > 1)
                <h3>{{ $option->name ?: $labels['variant'].' '.($index + 1) }}</h3>
            @endif
            @foreach ($option->stages as $stage)
                @if ($option->stages->count() > 1) <h4>{{ $stage->name }}</h4> @endif
                <table>
                    <thead><tr><th>{{ $labels['manipulation'] }}</th><th class="number">{{ $labels['quantity'] }}</th><th class="number">{{ $labels['unit_price'] }}</th><th class="number">{{ $labels['total'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($stage->items as $item)
                            <tr><td>{{ $item->description }}</td><td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount($item->quantity) }}</td><td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount((float) $item->unit_price) }} GEL</td><td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount($item->line_total) }} GEL</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="total">{{ $labels['stage_total'] }}: {{ \App\Support\TreatmentPlanDocument::formatAmount($stage->subtotal) }} GEL</div>
            @endforeach
            @if ($option->discount_amount > 0)
                <div class="total">{{ $labels['subtotal'] }}: {{ \App\Support\TreatmentPlanDocument::formatAmount($option->total_amount) }} GEL</div>
                <div class="total">{{ $labels['discount'] }}: {{ \App\Support\TreatmentPlanDocument::formatDiscount($option) }}</div>
                <div class="total">{{ $labels['final_total'] }}: {{ \App\Support\TreatmentPlanDocument::formatAmount($option->final_amount) }} GEL</div>
            @else
                <div class="total">{{ $labels['final_total'] }}: {{ \App\Support\TreatmentPlanDocument::formatAmount($option->final_amount) }} GEL</div>
            @endif
            <div class="details">
                @if (filled($option->estimated_duration)) <div><strong>{{ $labels['duration'] }}:</strong> {{ $option->estimated_duration }}</div> @endif
            </div>
        </div>
    @endforeach
</body>
</html>
