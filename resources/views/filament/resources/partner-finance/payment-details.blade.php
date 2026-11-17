<div class="space-y-4">
    @if($reason)<p class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">{{ $reason }}</p>@endif
    <dl class="grid grid-cols-2 gap-3 text-sm">
        <div><dt>{{ __('israeli-payments.patient') }}</dt><dd>{{ $payment->patient?->full_name ?? '—' }}</dd></div>
        <div><dt>{{ __('israeli-payments.amount') }}</dt><dd>{{ \App\Support\Currency::format($payment->amount, $payment->currency) }}</dd></div>
        <div><dt>{{ __('israeli-payments.date') }}</dt><dd>{{ $payment->paid_at->format('d.m.Y') }}</dd></div>
        <div><dt>{{ __('israeli-payments.method') }}</dt><dd>{{ $payment->payment_method_label }}</dd></div>
        <div><dt>{{ __('israeli-payments.account') }}</dt><dd>{{ __('israeli-payments.'.($payment->payment_method === 'cash' ? 'cash' : 'bank')) }}</dd></div>
        <div><dt>{{ __('israeli-payments.notes') }}</dt><dd>{{ $payment->notes ?: '—' }}</dd></div>
        @if($payment->cancelled_at)
            <div><dt>{{ __('israeli-payments.cancelled') }}</dt><dd>{{ $payment->cancelled_at->format('d.m.Y H:i') }}</dd></div>
            <div><dt>{{ __('israeli-payments.reason') }}</dt><dd>{{ $payment->cancellation_reason }}</dd></div>
        @endif
    </dl>
    <details>
        <summary class="cursor-pointer text-sm font-semibold">{{ __('israeli-payments.history') }}</summary>
        @foreach($audits as $audit)
            <div class="mt-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                <p>{{ __('israeli-payments.'.$audit->action) }} · {{ $audit->actor_name }} · {{ $audit->created_at->format('d.m.Y H:i') }}</p>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    @foreach(['old_values' => 'before', 'new_values' => 'after'] as $field => $label)
                        <div><strong>{{ __('israeli-payments.'.$label) }}</strong>
                            @foreach(['patient_name', 'amount', 'currency', 'paid_at', 'payment_method', 'account', 'notes', 'cancellation_reason', 'restoration_reason'] as $key)
                                @if(array_key_exists($key, $audit->{$field}))<p>{{ __('israeli-payments.'.$key) }}: {{ $audit->{$field}[$key] ?? '—' }}</p>@endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </details>
</div>
