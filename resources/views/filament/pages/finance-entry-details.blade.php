@php
    $account = $entry->detail_account ?? null;
    $accountLabel = \App\Models\FinanceTransaction::CASH_SOURCES[$account] ?? \App\Enums\PartnerAccount::tryFrom((string) $account)?->label() ?? \App\Enums\PaymentMethod::labelFor($account) ?? $account;
    $category = $entry->detail_category ?? $entry->category_name ?? null;
    $category = \App\Models\FinanceTransaction::CATEGORIES[$category] ?? \App\Models\PartnerFinanceTransaction::TRANSFER_CATEGORIES[$category] ?? $category;
@endphp
<div class="mt-1 space-y-1 text-xs" style="overflow-wrap:anywhere;min-width:12rem;max-width:28rem">
    @if($entry->detail_patient_id ?? null)
        <a class="text-primary-600 hover:underline" href="{{ route('filament.admin.resources.patients.view', ['record' => $entry->detail_patient_id]) }}">{{ $entry->detail_patient }}</a>
    @elseif($entry->detail_recipient ?? null)<p>{{ $entry->detail_recipient }}</p>@endif
    @if($account)<p class="text-gray-500">ანგარიში: {{ $accountLabel }}@if($entry->detail_to_account) → {{ \App\Enums\PartnerAccount::tryFrom($entry->detail_to_account)?->label() ?? $entry->detail_to_account }}@endif</p>@endif
    @if($category)<p>{{ $category }}@if($entry->detail_subcategory ?? null) → {{ $entry->detail_subcategory }}@endif</p>@endif
    @if(($entry->detail_notes ?? null) && $entry->detail_notes !== $entry->description)<p class="whitespace-pre-line">{{ $entry->detail_notes }}</p>@endif
    @if(($entry->detail_type ?? null) === 'currency_exchange')
        <p class="font-medium">{{ number_format($entry->detail_from_amount, 2) }} {{ $entry->detail_from_currency }} → {{ number_format($entry->detail_to_amount, 2) }} {{ $entry->detail_to_currency }} · კურსი: {{ rtrim(rtrim(number_format($entry->detail_rate, 6, '.', ''), '0'), '.') }}</p>
    @endif
    @if(preg_match('/^(finance|payment|partner|partner-payment|bank|bank-fee|cashbox|cashbox-expense|sale|payroll|advance-expense|advance-return|advance-other):[0-9]+$/', $entry->detail_key ?? ''))
        <button type="button" class="font-medium text-primary-600 hover:underline" wire:click="mountAction('financeRecord', {{ \Illuminate\Support\Js::from(['key' => $entry->detail_key]) }})">დეტალები / რედაქტირება</button>
    @else
        <p class="text-gray-500">დაცული ჩანაწერი — ცვლილება შესაბამის მოდულში.</p>
    @endif
</div>
