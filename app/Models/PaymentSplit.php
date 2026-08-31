<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PaymentSplit extends Model
{
    protected $fillable = ['payment_id', 'payment_method', 'amount', 'currency', 'exchange_rate'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::saving(function (PaymentSplit $split): void {
            $split->amount = Money::decimal($split->getAttributes()['amount'] ?? 0);
            $split->payment_method = Payment::normalizeMethod($split->payment_method);
            $paymentCurrency = $split->payment()->value('currency');
            $split->currency = $split->currency ?: $paymentCurrency ?: Currency::DEFAULT;

            if (! Currency::isSupported($split->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }
            if ($paymentCurrency && $split->currency !== $paymentCurrency && (float) $split->exchange_rate <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => 'განსხვავებული ვალუტისთვის მიუთითეთ კურსი.']);
            }

            if (! PaymentMethod::isSupported($split->payment_method)) {
                throw ValidationException::withMessages(['payment_method' => 'გადახდის მეთოდი არასწორია.']);
            }

            if ((float) $split->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'თანხა უნდა იყოს 0-ზე მეტი.']);
            }
        });

        static::created(function (PaymentSplit $split): void {
            $split->audit('split_created', null, $split->auditValues());
        });
        static::updated(function (PaymentSplit $split): void {
            $split->audit('split_updated', $split->getRawOriginal(), $split->auditValues());
        });
        static::deleted(function (PaymentSplit $split): void {
            $split->audit('split_deleted', $split->auditValues(), null);
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    private function auditValues(): array
    {
        return ['id' => $this->getKey(), 'payment_method' => $this->payment_method, 'amount' => $this->amount, 'currency' => $this->currency, 'exchange_rate' => $this->exchange_rate];
    }

    private function audit(string $action, ?array $oldValues, ?array $newValues): void
    {
        PaymentAudit::create([
            'payment_id' => $this->payment_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
