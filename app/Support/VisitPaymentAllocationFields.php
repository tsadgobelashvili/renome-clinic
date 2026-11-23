<?php

namespace App\Support;

use App\Models\Patient;
use App\Models\TreatmentCase;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

class VisitPaymentAllocationFields
{
    public static function schema(): array
    {
        $context = function (Get $get, $livewire): array {
            $parent = isset($livewire->form) ? $livewire->form->getRawState() : [];
            $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
            $patientId = $get('patient_id') ?: ($parent['patient_id'] ?? $record?->patient_id);
            $items = $get('treatmentCaseItems') ?? $get('tomographyItems') ?? $parent['treatmentCaseItems'] ?? [];
            $categories = TreatmentCase::whereIn('id', collect($items)->pluck('treatment_case_id')->filter())->pluck('category', 'id');
            $ct = $other = false;
            foreach ($items as $item) {
                if ((float) ($item['unit_price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)) <= 0) {
                    continue;
                }
                if (($categories[$item['treatment_case_id'] ?? null] ?? null) === 'tomography') {
                    $ct = true;
                } else {
                    $other = true;
                }
            }

            return ['mixed' => $ct && $other && (Patient::find($patientId)?->isIsraelPartner() ?? false),
                'discount' => max((float) $get('discount_value'), (float) $get('discount_amount'), (float) $get('discount_percent'),
                    (float) ($parent['discount_value'] ?? 0), (float) ($parent['discount_amount'] ?? 0), (float) ($record?->discount_amount ?? 0)),
                'currency' => $get('currency') ?: ($parent['currency'] ?? $record?->currency ?? Currency::DEFAULT)];
        };
        $mixed = fn (Get $get, $livewire) => $context($get, $livewire)['mixed'];
        $paying = fn (Get $get, $livewire) => $mixed($get, $livewire)
            && collect($get('paymentSplits') ?? $get('splits') ?? [])->sum('amount') > 0;

        return [
            Hidden::make('payment_request_key')->default(fn () => (string) Str::uuid())->required(),
            TextInput::make('ct_payment_amount')->label(__('visit-payments.ct'))
                ->suffix(fn (Get $get, $livewire) => $context($get, $livewire)['currency'])
                ->numeric()->minValue(0)->step(0.01)->visible($mixed)->required($paying)->dehydrated($mixed)
                ->helperText(__('visit-payments.allocation_help')),
            TextInput::make('treatment_payment_amount')->label(__('visit-payments.treatment'))
                ->suffix(fn (Get $get, $livewire) => $context($get, $livewire)['currency'])
                ->numeric()->minValue(0)->step(0.01)->visible($mixed)->required($paying)->dehydrated($mixed),
            TextInput::make('ct_discount_amount')->label(__('visit-payments.ct_discount'))->numeric()->minValue(0)->step(0.01)
                ->visible(fn (Get $get, $livewire) => $mixed($get, $livewire) && $context($get, $livewire)['discount'] > 0)
                ->required(fn (Get $get, $livewire) => $paying($get, $livewire) && $context($get, $livewire)['discount'] > 0)
                ->dehydrated($mixed),
        ];
    }
}
