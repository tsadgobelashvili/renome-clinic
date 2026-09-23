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
                                    <td class="number">{{ $item->quantity }}</td>
                                    <td class="number">{{ number_format((float) $item->unit_price, 2) }} ₾</td>
                                    <td class="number">{{ number_format($item->line_total, 2) }} ₾</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" scope="row">ეტაპის ჯამი</th>
                                <td class="number">{{ number_format($stage->subtotal, 2) }} ₾</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endforeach
            <div class="renome-plan-document__totals">
                @if ($option->discount_amount > 0)
                    <p>საწყისი ჯამი: {{ number_format($option->total_amount, 2) }} ₾</p>
                    <p>ფასდაკლება: {{ $option->discount_display }}</p>
                @endif
                <p><strong>საბოლოო ჯამი: {{ number_format($option->final_amount, 2) }} ₾</strong></p>
            </div>
            @if (filled($option->estimated_duration))
                <p class="renome-plan-document__duration">სავარაუდო დრო: {{ $option->estimated_duration }}</p>
            @endif
        </section>
    @endforeach
</div>
