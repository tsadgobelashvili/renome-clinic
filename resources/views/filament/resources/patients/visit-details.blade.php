@php
    $money = fn ($amount, $currency = null) => $amount === null ? '—' : \App\Support\Currency::format($amount, $currency ?: $visit->currency);
    $notes = collect(['complaint', 'diagnosis', 'treatment_notes', 'doctor_notes', 'comment', 'notes'])
        ->filter(fn ($field) => filled($visit->{$field}));
@endphp
<div class="renome-patient-visit-details">
    <dl class="renome-patient-visit-summary">
        <div><dt>{{ __('patient-profile.date') }}</dt><dd>{{ $visit->visit_date?->format('d.m.Y') }} {{ substr((string) $visit->getAttribute('visit_time'), 0, 5) }}</dd></div>
        <div><dt>{{ __('patient-profile.doctor') }}</dt><dd>{{ $visit->doctor?->full_name ?? '—' }}</dd></div>
        <div><dt>{{ __('patient-profile.status') }}</dt><dd>{{ __('patient-profile.'.$visit->visit_type) }} · {{ __('patient-profile.'.($visit->is_cancelled ? 'cancelled' : $visit->payment_status.'_status')) }}</dd></div>
    </dl>
    <div class="renome-patient-visit-table">
        <table>
            <thead><tr>
                <th>{{ __('patient-profile.manipulation') }}</th><th>{{ __('patient-profile.teeth') }}</th>
                <th class="numeric">{{ __('patient-profile.quantity') }}</th><th class="numeric">{{ __('patient-profile.unit_price') }}</th><th class="numeric">{{ __('patient-profile.total') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($visit->treatmentCaseItems as $item)
                    <tr><td>{{ $item->display_name }}@if(filled($item->comment))<div class="text-xs text-gray-500">{{ $item->comment }}</div>@endif</td>
                        <td>{{ $item->teeth ?: '—' }}</td><td class="numeric">{{ $item->quantity }}</td>
                        <td class="numeric">{{ $money($item->unit_price, $item->currency) }}</td><td class="numeric">{{ $money($item->manipulation_total, $item->currency) }}</td></tr>
                @endforeach
                @if ((float) $visit->consultation_fee > 0)
                    <tr><td colspan="4">{{ __('patient-profile.consultation_fee') }}</td><td class="numeric">{{ $money($visit->consultation_fee) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>
    <dl class="renome-patient-visit-summary">
        <div><dt>{{ __('patient-profile.total') }}</dt><dd>{{ $money($visit->total_price) }}</dd></div>
        <div><dt>{{ __('patient-profile.discount') }}</dt><dd>{{ $visit->discount_display }}@if ($visit->discount_reason)<div>{{ \App\Models\Visit::DISCOUNT_REASONS[$visit->discount_reason] ?? $visit->discount_reason }}</div>@endif {{ $visit->discount_comment }}</dd></div>
        <div><dt>{{ __('patient-profile.charged') }}</dt><dd>{{ $money($visit->net_amount) }}</dd></div>
        <div><dt>{{ __('patient-profile.remaining') }}</dt><dd>{{ $money($visit->remaining_amount) }}</dd></div>
    </dl>
    <section>
        <h3>{{ __('patient-profile.payments') }}</h3>
        @forelse ($payments as $payment)
            <div class="renome-patient-visit-payment">
                <span>{{ $payment->payment_date?->format('d.m.Y') }}</span>
                <strong>{{ $money($payment->amount, $payment->currency) }}</strong>
                <span>{{ $payment->source === 'clinic' && $payment->splits->isNotEmpty() ? $payment->method_display : \App\Enums\PaymentMethod::labelFor($payment->payment_method).' '.$money($payment->amount, $payment->currency) }}</span>
                @if (filled($payment->comment))<div class="w-full whitespace-pre-line">{{ $payment->comment }}</div>@endif
            </div>
        @empty
            <p>{{ __('patient-profile.no_payments') }}</p>
        @endforelse
    </section>
    @if ($visit->treatmentCaseItems->contains(fn ($item) => $item->directExpenses->isNotEmpty()))
        <section>
            <h3>{{ __('patient-profile.expenses') }}</h3>
            <div class="renome-patient-visit-table">
                <table><thead><tr><th>{{ __('patient-profile.manipulation') }}</th><th>{{ __('patient-profile.expense') }}</th><th class="numeric">{{ __('patient-profile.quantity') }}</th><th class="numeric">{{ __('patient-profile.amount') }}</th></tr></thead>
                    <tbody>@foreach ($visit->treatmentCaseItems as $item)@foreach ($item->directExpenses as $expense)
                        <tr><td>{{ $item->display_name }}</td><td>{{ $expense->name }}
                            <div class="text-xs text-gray-500">{{ collect([$expense->expenseDirection?->name, $expense->expenseType?->name])->filter()->join(' / ') }}</div>
                        </td><td class="numeric">{{ $expense->quantity }}</td><td class="numeric">{{ $money($expense->amount, $expense->currency) }}</td></tr>
                    @endforeach @endforeach</tbody>
                </table>
            </div>
        </section>
    @endif
    @foreach ($notes as $field)
        <section><h3>{{ __('patient-profile.'.$field) }}</h3><p class="whitespace-pre-line">{{ $visit->{$field} }}</p></section>
    @endforeach
    @if ($visit->is_cancelled && filled($visit->cancellation_reason))
        <section><h3>{{ __('patient-profile.cancelled') }}</h3><p>{{ $visit->cancellation_reason }}</p></section>
    @endif
</div>
