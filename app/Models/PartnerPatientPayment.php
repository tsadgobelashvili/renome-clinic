<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PartnerPatientPayment extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $fillable = [
        'visit_id',
        'patient_id',
        'amount',
        'currency',
        'payment_method',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PartnerPatientPayment $payment): void {
            if ($payment->visit_id && ! Visit::query()->whereKey($payment->visit_id)->where('patient_id', $payment->patient_id)->exists()) {
                throw ValidationException::withMessages(['visit_id' => 'ვიზიტი გაუქმებულია ან სხვა პაციენტს ეკუთვნის.']);
            }
            $payment->amount = Money::minorUnits($payment->getAttributes()['amount'] ?? 0) / 100;
            $payment->currency = strtoupper((string) ($payment->currency ?: Currency::DEFAULT));
            $payment->payment_method = PaymentMethod::normalize($payment->payment_method);
            $payment->notes = filled($payment->notes) ? trim((string) $payment->notes) : null;

            if (Money::minorUnits($payment->amount) <= 0) {
                throw ValidationException::withMessages(['amount' => 'გადახდის თანხა უნდა იყოს 0-ზე მეტი.']);
            }

            if (! Currency::isSupported($payment->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }

            if (! PaymentMethod::isSupported($payment->payment_method)) {
                throw ValidationException::withMessages(['payment_method' => 'არჩეული გადახდის მეთოდი არასწორია.']);
            }

            $isPartnerPatient = Patient::query()
                ->whereKey($payment->patient_id)
                ->whereHas('patientGroup', fn ($query) => $query->where(
                    'slug',
                    PatientGroup::ISRAEL_PARTNER_SLUG,
                ))
                ->exists();

            if (! $isPartnerPatient) {
                throw ValidationException::withMessages([
                    'patient_id' => 'ისრაელის გადახდა მხოლოდ ისრაელის პაციენტისთვის შეიძლება.',
                ]);
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return PaymentMethod::labelFor($this->payment_method);
    }
}
