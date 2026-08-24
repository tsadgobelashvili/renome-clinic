<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <title>მკურნალობის გეგმა და კალკულაცია</title>
    <style>
        body { font-family: "{{ $exportFontFamily }}", sans-serif; color: #222; font-size: 12px; }
        h1, h2 { text-align: center; margin: 0; }
        h1 { font-size: 20px; }
        h2 { margin-top: 6px; margin-bottom: 26px; font-size: 16px; }
        .meta { margin-bottom: 20px; line-height: 1.8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .number { text-align: right; white-space: nowrap; }
        .total { margin-top: 18px; text-align: right; font-weight: bold; font-size: 15px; }
        .details { margin-top: 22px; line-height: 1.7; }
    </style>
</head>
<body>
    <h1>{{ $clinicName }}</h1>
    <h2>მკურნალობის გეგმა და კალკულაცია</h2>

    <div class="meta">
        <div><strong>პაციენტი:</strong> {{ $estimate->patient->full_name }}</div>
        <div><strong>თარიღი:</strong> {{ $estimate->estimate_date->format('d.m.Y') }}</div>
        @if ($estimate->doctor)
            <div><strong>ექიმი:</strong> {{ $estimate->doctor->full_name }}</div>
        @endif
    </div>

    @foreach ($estimate->options as $index => $option)
        @if ($estimate->options->count() > 1)
            <h3>{{ $option->name ?: 'ვარიანტი '.($index + 1) }}</h3>
        @endif
        @foreach ($option->stages as $stage)
            @if ($option->stages->count() > 1) <h4>{{ $stage->name }}</h4> @endif
            <table>
                <thead><tr><th>მანიპულაცია</th><th class="number">რაოდენობა</th><th class="number">ერთეულის ფასი</th><th class="number">ჯამი</th></tr></thead>
                <tbody>
                    @foreach ($stage->items as $item)
                        <tr><td>{{ $item->description }}</td><td class="number">{{ $item->quantity }}</td><td class="number">{{ number_format((float) $item->unit_price, 2) }} ₾</td><td class="number">{{ number_format($item->line_total, 2) }} ₾</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="total">ეტაპის ჯამი: {{ number_format($stage->subtotal, 2) }} ₾</div>
        @endforeach
        @if ($option->discount_amount > 0)
            <div class="total">საწყისი ჯამი: {{ number_format($option->total_amount, 2) }} ₾</div>
            <div class="total">ფასდაკლება: {{ $option->discount_display }}</div>
            <div class="total">საბოლოო თანხა: {{ number_format($option->final_amount, 2) }} ₾</div>
        @else
            <div class="total">საბოლოო ჯამი: {{ number_format($option->final_amount, 2) }} ₾</div>
        @endif
        <div class="details">
            @if (filled($option->estimated_duration)) <div><strong>სავარაუდო დრო:</strong> {{ $option->estimated_duration }}</div> @endif
        </div>
    @endforeach
</body>
</html>
