<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class TreatmentPlanDocument
{
    public const LANGUAGES = ['ka' => 'ქართული', 'en' => 'English', 'ru' => 'Русский'];

    public static function labels(string $language = 'ka'): array
    {
        validator(['language' => $language], ['language' => ['required', Rule::in(array_keys(self::LANGUAGES))]])->validate();

        return match ($language) {
            'en' => [
                'title' => 'Treatment Plan and Cost Estimate', 'patient' => 'Patient', 'doctor' => 'Doctor', 'date' => 'Date',
                'variant' => 'Variant', 'manipulation' => 'Manipulation', 'quantity' => 'Quantity', 'unit_price' => 'Unit price',
                'total' => 'Total', 'stage_total' => 'Stage total', 'subtotal' => 'Subtotal', 'discount' => 'Discount',
                'final_total' => 'Final total', 'duration' => 'Estimated duration',
                'clinic' => 'RenoMe Dental Clinic', 'address' => '8a Navtlughi St., Isani Mall, Tbilisi',
            ],
            'ru' => [
                'title' => 'План лечения и расчёт стоимости', 'patient' => 'Пациент', 'doctor' => 'Врач', 'date' => 'Дата',
                'variant' => 'Вариант', 'manipulation' => 'Манипуляция', 'quantity' => 'Количество', 'unit_price' => 'Цена за единицу',
                'total' => 'Итого', 'stage_total' => 'Итого за этап', 'subtotal' => 'Сумма до скидки', 'discount' => 'Скидка',
                'final_total' => 'Итоговая сумма', 'duration' => 'Предполагаемая длительность',
                'clinic' => 'Стоматологическая клиника RenoMe', 'address' => 'ул. Навтлуги 8а, Isani Mall, Тбилиси',
            ],
            default => [
                'title' => 'მკურნალობის გეგმა და კალკულაცია', 'patient' => 'პაციენტი', 'doctor' => 'ექიმი', 'date' => 'თარიღი',
                'variant' => 'ვარიანტი', 'manipulation' => 'მანიპულაცია', 'quantity' => 'რაოდენობა', 'unit_price' => 'ერთეულის ფასი',
                'total' => 'ჯამი', 'stage_total' => 'ეტაპის ჯამი', 'subtotal' => 'საწყისი ჯამი', 'discount' => 'ფასდაკლება',
                'final_total' => 'საბოლოო ჯამი', 'duration' => 'სავარაუდო დრო',
                'clinic' => 'რენომე თბილისი', 'address' => 'ნავთლუღის ქ. 8ა, Isani Mall',
            ],
        };
    }

    public const CONTACT = '+995 599 000 359 | info@renome.ge | renome.ge';
}
