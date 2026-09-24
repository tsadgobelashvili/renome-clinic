<div class="renome-plan-document">
    <dl class="renome-plan-document__header">
        <div><dt>პაციენტი</dt><dd>{{ $estimate->patient?->full_name ?? '—' }}</dd></div>
        <div><dt>თარიღი</dt><dd>{{ $estimate->estimate_date?->format('d.m.Y') ?? '—' }}</dd></div>
        <div><dt>ექიმი</dt><dd>{{ $estimate->doctor?->full_name ?? '—' }}</dd></div>
    </dl>

    @foreach ($estimate->options as $index => $option)
        <section class="renome-plan-document__variant">
            <h3>{{ $option->name ?: 'ვარიანტი '.($index + 1) }}</h3>
            @if (filled($option->estimated_duration))
                <p class="renome-plan-document__duration">სავარაუდო დრო: {{ $option->estimated_duration }}</p>
            @endif
            @foreach ($option->stages as $stage)
                @if ($option->stages->count() > 1)
                    <h4>{{ $stage->name }}</h4>
                @endif
                <div class="renome-plan-document__table">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">მანიპულაცია</th>
                                <th scope="col" class="number">რაოდენობა</th>
                                <th scope="col" class="number">ერთეულის ფასი</th>
                                <th scope="col" class="number">ჯამი</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stage->items as $item)
                                <tr>
                                    <td>{{ $item->description }}</td>
                                    <td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount($item->quantity) }}</td>
                                    <td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount((float) $item->unit_price) }} ₾</td>
                                    <td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount($item->line_total) }} ₾</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" scope="row">ეტაპის ჯამი</th>
                                <td class="number">{{ \App\Support\TreatmentPlanDocument::formatAmount($stage->subtotal) }} ₾</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endforeach
            <div class="renome-plan-document__totals">
                @if ($option->discount_amount > 0)
                    <p>საწყისი ჯამი: {{ \App\Support\TreatmentPlanDocument::formatAmount($option->total_amount) }} ₾</p>
                    <p>ფასდაკლება: {{ $option->discount_display }}</p>
                @endif
                <p><strong>საბოლოო ჯამი: {{ \App\Support\TreatmentPlanDocument::formatAmount($option->final_amount) }} ₾</strong></p>
            </div>
            @if (filled($option->estimated_duration))
                <p class="renome-plan-document__duration">სავარაუდო დრო: {{ $option->estimated_duration }}</p>
            @endif
        </section>
    @endforeach
</div>
