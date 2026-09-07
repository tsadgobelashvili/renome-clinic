@php
    $payout = app(\App\Services\IsraeliSalaryCarryService::class)->preview(
        (int) $doctorId, (float) $previewPayment,
        is_numeric($actualPaidUsd ?? null) && (float) $actualPaidUsd >= 0 ? (float) $actualPaidUsd : null,
    );
    $carryLabel = fn ($amount) => number_format(abs($amount), 2).' USD '.($amount > 0 ? 'advance' : ($amount < 0 ? 'remaining' : 'balanced'));
@endphp
<span>Opening carry: <strong>{{ $carryLabel($payout['opening_carry_usd']) }}</strong></span>
<span>Calculated: <strong>{{ number_format($payout['calculated_usd'], 2) }} USD</strong></span>
@if ($editable ?? false)
    <label>Actual paid USD
        <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="actualPaidUsd"
            placeholder="{{ number_format($payout['calculated_usd'], 2, '.', '') }}"
            class="w-28 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
        <span class="text-gray-500">Empty = calculated payout</span>
    </label>
    @error('actualPaidUsd') <span class="text-danger-600">{{ $message }}</span> @enderror
@else
    <span>Actual paid: <strong>{{ number_format($payout['actual_paid_usd'], 2) }} USD</strong></span>
@endif
<span>Difference: <strong>{{ $payout['difference_usd'] > 0 ? '+' : '' }}{{ $carryLabel($payout['difference_usd']) }}</strong></span>
<span>Closing carry: <strong>{{ $carryLabel($payout['closing_carry_usd']) }}</strong></span>
