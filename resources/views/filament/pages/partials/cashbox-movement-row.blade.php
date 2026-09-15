@php
    $presentation = \App\Support\CashboxMovementPresentation::class;
    $color = $presentation::color($transaction);
    $category = $presentation::category($transaction);
    $description = $presentation::description($transaction);
@endphp
<tr class="even:bg-gray-50 dark:even:bg-white/5">
    <td class="whitespace-nowrap p-2">{{ $transaction->transaction_date->timezone(config('app.timezone'))->format('H:i') }}</td>
    <td class="p-2"><x-filament::badge :color="$color">{{ $presentation::type($transaction) }}</x-filament::badge></td>
    <td class="max-w-56 p-2"><div class="truncate" title="{{ $category }}">{{ $category }}</div></td>
    <td class="max-w-64 p-2"><div class="truncate" title="{{ $description }}">{{ $description }}</div></td>
    <td class="whitespace-nowrap p-2"><x-filament::badge color="gray">{{ \App\Enums\PaymentMethod::options()[$transaction->payment_method] ?? ($transaction->payment_method ?: '—') }}</x-filament::badge></td>
    <td @class([
        'whitespace-nowrap p-2 text-right font-semibold',
        'text-red-600 dark:text-red-400' => $color === 'danger',
        'text-green-600 dark:text-green-400' => $color === 'success',
        'text-gray-500 dark:text-gray-400' => $color === 'gray',
    ])>{{ $presentation::sign($transaction) }}{{ $amountDisplay }}</td>
</tr>
