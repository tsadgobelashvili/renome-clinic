<div class="space-y-2">
    @forelse ($payments as $payment)
        <div @class([
            'flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm',
            'border-gray-200 dark:border-white/10' => ! $payment->trashed(),
            'border-gray-200 bg-gray-50 text-gray-500 opacity-75 dark:border-white/10 dark:bg-white/5' => $payment->trashed(),
        ]) wire:key="visit-payment-history-{{ $payment->getKey() }}">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium tabular-nums {{ $payment->trashed() ? 'line-through' : 'text-emerald-600' }}">
                        {{ $payment->method_display }}
                    </span>
                    @if ($payment->trashed())
                        <span class="rounded bg-gray-200 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">გაუქმებული</span>
                    @endif
                </div>
                <div class="mt-0.5 text-xs text-gray-500">
                    {{ $payment->payment_date?->format('d.m.Y') ?? '—' }}
                </div>
            </div>

            @if ((auth()->user()?->isOwner() ?? false) && ! $payment->trashed())
                <div class="flex items-center gap-2">
                    <button type="button" class="text-xs font-medium text-primary-600 hover:text-primary-700"
                        wire:click="mountAction('editHistoricalPayment', { payment: {{ $payment->getKey() }} })">
                        რედაქტირება
                    </button>
                    <button type="button" class="text-xs font-medium text-rose-600 hover:text-rose-700"
                        wire:click="mountAction('voidHistoricalPayment', { payment: {{ $payment->getKey() }} })">
                        გაუქმება
                    </button>
                </div>
            @endif
        </div>
    @empty
        <div class="text-sm text-gray-500">გადახდები არ არის</div>
    @endforelse
</div>
