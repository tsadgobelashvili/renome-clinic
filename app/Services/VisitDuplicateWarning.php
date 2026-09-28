<?php
namespace App\Services;

use App\Models\Visit;
use Illuminate\Validation\ValidationException;

class VisitDuplicateWarning
{
    public static function exists(array $data): bool
    {
        if (empty($data['patient_id']) || empty($data['doctor_id']) || empty($data['visit_date'])) { return false; }
        return Visit::query()->where('patient_id', $data['patient_id'])->where('doctor_id', $data['doctor_id'])
            ->whereDate('visit_date', $data['visit_date'])->exists();
    }

    public static function validate(array $data): void
    {
        if (self::exists($data) && ! filter_var($data['acknowledge_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['acknowledge_duplicate' => 'ამ პაციენტზე, ექიმსა და თარიღზე ვიზიტი უკვე არსებობს. შეამოწმეთ ჩანაწერი; თუ ეს სხვა ვიზიტია, მონიშნეთ დადასტურება.']);
        }
    }

    public static function field(): \Filament\Forms\Components\Checkbox
    {
        return \Filament\Forms\Components\Checkbox::make('acknowledge_duplicate')
            ->label('გადავამოწმე — ეს სხვა ვიზიტია')
            ->helperText('ამ პაციენტს იმავე ექიმთან ამ თარიღზე ვიზიტი უკვე აქვს. შენახვამდე გადაამოწმეთ.')
            ->default(false)->columnSpanFull()
            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get, $livewire): bool => (! method_exists($livewire, 'getRecord') || ! $livewire->getRecord()?->exists) && self::exists([
                'patient_id' => $get('patient_id'), 'doctor_id' => $get('doctor_id'), 'visit_date' => $get('visit_date'),
            ]));
    }
}
