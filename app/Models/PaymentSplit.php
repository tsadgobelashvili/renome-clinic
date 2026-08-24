<?php

namespace App\Models;

use App\Support\CashboxManager;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PaymentSplit extends Model
{
    protected $fillable = ['payment_id', 'payment_method', 'amount', 'currency'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (PaymentSplit $split): void {
            $split->amount = Payment::toCents($split->getAttributes()['amount'] ?? 0) / 100;
            $paymentCurrency = $split->payment()->value('currency');
            $split->currency = $split->currency ?: $paymentCurrency ?: Currency::DEFAULT;

            if ((! Currency::isSupported($split->currency)) || ($paymentCurrency && $split->currency !== $paymentCurrency)) {
                throw ValidationException::withMessages(['currency' => 'გადახდის ნაწილები ერთი ვალუტით უნდა იყოს.']);
            }

            if (! array_key_exists((string) $split->payment_method, Payment::METHOD_LABELS)) {
                throw ValidationException::withMessages(['payment_method' => 'გადახდის მეთოდი არასწორია.']);
            }

            if ((float) $split->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'თანხა უნდა იყოს 0-ზე მეტი.']);
            }
        });

        static::created(function (PaymentSplit $split): void {
            $split->audit('split_created', null, $split->auditValues());
            app(CashboxManager::class)->syncPayment($split->payment);
        });
        static::updated(function (PaymentSplit $split): void {
            $split->audit('split_updated', $split->getRawOriginal(), $split->auditValues());
            app(CashboxManager::class)->syncPayment($split->payment);
        });
        static::deleted(function (PaymentSplit $split): void {
            $split->audit('split_deleted', $split->auditValues(), null);
            app(CashboxManager::class)->syncPayment($split->payment);
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    private function auditValues(): array
    {
        return ['id' => $this->getKey(), 'payment_method' => $this->payment_method, 'amount' => $this->amount, 'currency' => $this->currency];
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
