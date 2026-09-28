<?php
namespace App\Services;

class HistoricalPayment
{
    public static function attributes(array $data): array
    {
        $historical = filter_var($data['is_historical'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $historical) {
            return ['is_historical' => false, 'payment_date' => today()->toDateString()];
        }
        abort_unless(auth()->user()?->isOwner() || auth()->user()?->isAdministrator(), 403);
        validator($data, [
            'payment_date' => 'required|date_format:Y-m-d|before:today',
            'products' => 'nullable|array|max:0',
        ], ['products.max' => 'ძველი გადახდის რეჟიმში მხოლოდ ვიზიტის მომსახურება შეიტანეთ.'])->validate();
        return ['is_historical' => true, 'payment_date' => $data['payment_date']];
    }
}
