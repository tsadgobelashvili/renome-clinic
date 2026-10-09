@php
    $patient = null;
    if ($record instanceof \App\Models\Payment) {
        $record->loadMissing(['visit.patient', 'splits']);
        $patient = $record->visit?->patient;
    } elseif (method_exists($record, 'patient')) {
        $record->loadMissing('patient');
        $patient = $record->patient;
    }
    $employee = null;
    $documentCurrency = $record->currency;
    if (method_exists($record, 'employee')) {
        $record->loadMissing('employee');
        $employee = $record->employee;
    } elseif ($record instanceof \App\Models\EmployeeAdvanceEntry) {
        $record->loadMissing('advance.employee');
        $employee = $record->advance?->employee;
        $documentCurrency = $record->advance?->currency;
    }
    foreach (['expenseCategory', 'expenseSubcategory', 'expenseDirection', 'expenseType'] as $relation) {
        if (method_exists($record, $relation)) $record->loadMissing($relation);
    }
    $labels = ['transaction_date' => 'თარიღი / დრო', 'payment_date' => 'გადახდის თარიღი', 'paid_at' => 'გადახდის თარიღი', 'transacted_at' => 'თარიღი / დრო',
        'source' => 'წყარო', 'type' => 'ტიპი', 'cash_source' => 'ნაღდი თანხის წყარო', 'payment_method' => 'მეთოდი',
        'from_account' => 'ანგარიშიდან', 'to_account' => 'ანგარიშზე', 'account_identifier' => 'ანგარიში', 'recipient' => 'მიმღები',
        'counterparty_name' => 'მიმღები', 'category' => 'კატეგორია', 'description' => 'აღწერა', 'note' => 'შენიშვნა', 'notes' => 'შენიშვნა', 'comment' => 'შენიშვნა', 'reference' => 'რეფერენსი',
        'sold_at' => 'თარიღი / დრო', 'date' => 'თარიღი', 'expense_date' => 'თარიღი', 'finalized_at' => 'დადასტურების თარიღი', 'period_start' => 'პერიოდის დასაწყისი', 'period_end' => 'პერიოდის დასასრული'];
@endphp
<div class="space-y-3 text-sm" style="max-height:65vh;overflow:auto;overflow-wrap:break-word;">
    <p class="text-xs text-gray-500">თავდაპირველი ჩანაწერი #{{ $record->getKey() }}</p>
    @if($employee)<p>მიმღები: {{ $employee->full_name }}</p>@endif
    @if($patient && \App\Filament\Resources\Patients\PatientResource::canView($patient))
        <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Patients\PatientResource::getUrl('view', ['record' => $patient]) }}">{{ $patient->full_name }}</a>
    @endif
    <dl class="grid gap-3 sm:grid-cols-2">
        @foreach($labels as $field => $label)
            @if(filled($record->{$field}))
                @php
                    $value = $record->{$field};
                    if ($value instanceof \DateTimeInterface) $value = $value->format('d.m.Y H:i');
                    elseif ($field === 'payment_method') $value = \App\Enums\PaymentMethod::labelFor($value);
                    elseif ($field === 'cash_source') $value = \App\Models\FinanceTransaction::CASH_SOURCES[$value] ?? $value;
                    elseif (in_array($field, ['from_account', 'to_account'])) $value = \App\Enums\PartnerAccount::tryFrom($value)?->label() ?? $value;
                    elseif ($field === 'source') $value = ['clinic' => 'კლინიკა', 'israeli' => 'ისრაელი'][$value] ?? $value;
                    elseif ($field === 'type') $value = (\App\Models\FinanceTransaction::TYPES + \App\Models\PartnerFinanceTransaction::TYPES)[$value] ?? $value;
                    elseif ($field === 'category') $value = \App\Models\FinanceTransaction::CATEGORIES[$value] ?? \App\Models\PartnerFinanceTransaction::TRANSFER_CATEGORIES[$value] ?? $value;
                @endphp
                <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $value }}</dd></div>
            @endif
        @endforeach
        @foreach(['expenseCategory' => 'კატეგორია', 'expenseSubcategory' => 'ქვეკატეგორია', 'expenseDirection' => 'მიმართულება', 'expenseType' => 'ხარჯის ტიპი'] as $relation => $label)
            @if($record->relationLoaded($relation) && $record->{$relation})<div><dt>{{ $label }}</dt><dd>{{ $record->{$relation}->name }}</dd></div>@endif
        @endforeach
    </dl>
    @if($record->type === 'currency_exchange')
        <p class="font-semibold">{{ \App\Support\Currency::format($record->from_amount, $record->from_currency) }} → {{ \App\Support\Currency::format($record->to_amount, $record->to_currency) }} · კურსი {{ $record->exchange_rate }}</p>
    @elseif(($amount = $record->amount ?? $record->net_amount ?? $record->total) !== null)<p class="font-semibold">{{ \App\Support\Currency::format($amount, $documentCurrency) }}</p>@endif
    @if($record instanceof \App\Models\Payment)<p>{{ $record->method_display }}</p>@endif
    @if($reason)<p class="rounded-lg bg-gray-50 p-3 dark:bg-white/5" role="note">{{ $reason }}</p>@endif
</div>
