<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <title>პაციენტის მკურნალობის ისტორია</title>
    <style>
        @page { margin: 32px 34px; }
        body { font-family: "{{ $exportFontFamily }}", sans-serif; color: #24313d; font-size: 10px; line-height: 1.45; }
        h1, h2 { margin: 0; text-align: center; }
        h1 { color: #0f766e; font-size: 18px; }
        h2 { margin-top: 4px; font-size: 14px; }
        h3 { margin: 18px 0 7px; color: #0f766e; font-size: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        .meta { margin-top: 18px; padding: 10px; background: #f8fafc; border: 1px solid #dbe4ea; }
        .meta-grid { width: 100%; border-collapse: collapse; }
        .meta-grid td { width: 50%; padding: 2px 5px; vertical-align: top; }
        .summary { width: 100%; border-collapse: collapse; }
        .summary th, .summary td { padding: 6px; border: 1px solid #cbd5e1; text-align: right; }
        .summary th:first-child, .summary td:first-child { text-align: left; }
        .summary th { background: #f1f5f9; }
        .visit { margin-top: 13px; page-break-inside: avoid; border: 1px solid #cbd5e1; }
        .visit-header { padding: 7px 9px; background: #ecfdf5; }
        .visit-body { padding: 8px 9px; }
        .visit-meta { color: #475569; }
        .items { width: 100%; margin-top: 7px; border-collapse: collapse; }
        .items th, .items td { padding: 5px; border: 1px solid #dbe4ea; text-align: left; }
        .items th { background: #f8fafc; font-size: 9px; }
        .number { text-align: right !important; white-space: nowrap; }
        .comment { margin-top: 7px; }
        .payment { margin-top: 5px; padding: 5px 7px; background: #f8fafc; }
        .muted { color: #64748b; }
        .empty { padding: 10px; color: #64748b; border: 1px dashed #cbd5e1; }
    </style>
</head>
<body>
    <h1>RenoMe Dental Clinic</h1>
    <h2>პაციენტის მკურნალობის ისტორია</h2>

    <div class="meta">
        <table class="meta-grid">
            <tr>
                <td><strong>სახელი და გვარი:</strong> {{ $patient->full_name }}</td>
                <td><strong>ტელეფონი:</strong> {{ $patient->phone ?: '—' }}</td>
            </tr>
            <tr>
                <td><strong>პირადი ნომერი:</strong> {{ $patient->personal_id ?: '—' }}</td>
                <td><strong>დაბადების თარიღი:</strong> {{ $patient->birth_date?->format('d.m.Y') ?: '—' }}</td>
            </tr>
            @if (filled($patient->notes))
                <tr><td colspan="2"><strong>შენიშვნა:</strong> {{ $patient->notes }}</td></tr>
            @endif
        </table>
    </div>

    <h3>ფინანსური შეჯამება</h3>
    @if ($financialSummaries === [])
        <div class="empty">ფინანსური მონაცემები ჯერ არ არის.</div>
    @else
        <table class="summary">
            <thead><tr><th>ვალუტა</th><th>ღირებულება</th><th>გადახდილი</th><th>დარჩენილი</th></tr></thead>
            <tbody>
                @foreach ($financialSummaries as $currency => $summary)
                    <tr>
                        <td>{{ \App\Support\Currency::symbol($currency) }}</td>
                        <td>{{ \App\Support\Currency::format($summary['net_amount'], $currency) }}</td>
                        <td>{{ \App\Support\Currency::format($summary['paid_amount'], $currency) }}</td>
                        <td>{{ \App\Support\Currency::format($summary['remaining_amount'], $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>ვიზიტების ქრონოლოგია</h3>
    @forelse ($patient->visits as $visit)
        <div class="visit">
            <div class="visit-header">
                <strong>{{ $visit->visit_date->format('d.m.Y') }}</strong>
                <span> · {{ $visit->doctor?->full_name ?? '—' }}</span>
                <span class="muted"> · {{ $visit->visit_type === 'consultation' ? 'კონსულტაცია' : 'მკურნალობა' }}</span>
            </div>
            <div class="visit-body">
                @if ($visit->treatmentCaseItems->isNotEmpty())
                    <table class="items">
                        <thead><tr><th>მანიპულაცია</th><th>კატეგორია</th><th>კბილები/უბანი</th><th class="number">რაოდ.</th><th class="number">ერთ. ფასი</th><th class="number">ჯამი</th></tr></thead>
                        <tbody>
                            @foreach ($visit->treatmentCaseItems as $item)
                                <tr>
                                    <td>{{ $item->display_name }}</td>
                                    <td>{{ $item->category_label }}</td>
                                    <td>{{ $item->teeth ?: '—' }}</td>
                                    <td class="number">{{ $item->quantity }}</td>
                                    <td class="number">{{ \App\Support\Currency::format($item->unit_price, $visit->currency) }}</td>
                                    <td class="number">{{ \App\Support\Currency::format($item->manipulation_total, $visit->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="muted">შესრულებული სამუშაო არ არის მითითებული.</div>
                @endif

                <div class="comment"><strong>ვიზიტის ღირებულება:</strong> {{ $visit->total_price === null ? '—' : \App\Support\Currency::format($visit->net_amount, $visit->currency) }}</div>
                @if (filled($visit->comment ?? $visit->notes))
                    <div class="comment"><strong>კომენტარი:</strong> {{ $visit->comment ?? $visit->notes }}</div>
                @endif

                @foreach ($visit->payments as $payment)
                    <div class="payment">
                        <strong>გადახდა:</strong> {{ $payment->payment_date->format('d.m.Y') }} · {{ \App\Support\Currency::format($payment->amount, $payment->currency) }} · {{ $payment->method_display }}
                        @if (filled($payment->comment)) <br><span class="muted">{{ $payment->comment }}</span> @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="empty">ვიზიტების ისტორია ჯერ არ არის.</div>
    @endforelse
</body>
</html>
