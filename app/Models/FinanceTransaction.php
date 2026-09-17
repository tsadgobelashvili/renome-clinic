<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\HasExpenseClassification;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class FinanceTransaction extends Model
{
    use HasExpenseClassification;

    public const FUNDING_CLINIC = 'clinic';

    public const FUNDING_ISRAELI = 'israeli';

    public const FUNDING_MIXED = 'mixed';

    public const TYPES = ['income' => 'შემოსავალი', 'expense' => 'ხარჯი'];

    public const CATEGORIES = [
        'laboratory' => 'ლაბორატორია', 'technician' => 'ტექნიკოსი',
        'materials' => 'მასალები / მომწოდებელი', 'salary' => 'ხელფასი',
        'rent' => 'ქირა', 'utilities' => 'კომუნალური', 'marketing' => 'მარკეტინგი',
        'transport' => 'ტრანსპორტი', 'office' => 'ოფისი', 'repair' => 'რემონტი / მოვლა',
        'taxes' => 'გადასახადები', 'bank_fees' => 'ბანკის / ტერმინალის საკომისიო',
        'lab_salary' => 'ლაბორატორიის ხელფასი', 'supplier' => 'მომწოდებელი',
        'other_expense' => 'სხვა ხარჯი', 'other_income' => 'სხვა შემოსავალი', 'other' => 'სხვა',
    ];

    public const CASH_SOURCES = [
        'current_cashier' => 'მიმდინარე სალარო',
        'withdrawn_cash' => 'ადრე გატანილი თანხა',
        'israeli' => 'ისრაელი',
    ];

    protected $fillable = [
        'salary_payout_allocation_id', 'expense_direction_id', 'expense_type_id', 'expense_category_id', 'expense_subcategory_id',
        'type', 'transaction_date', 'category', 'description', 'amount', 'currency',
        'payment_method', 'cash_source', 'funding_source', 'note', 'created_by',
        'salary_settlement_id', 'payroll_entry_id', 'reversal_of_finance_transaction_id',
        'employee_salary_settlement_id', 'lab_salary_settlement_id', 'clinic_cash_gel', 'israeli_cash_gel',
    ];

    protected function casts(): array
    {
        return ['transaction_date' => 'datetime', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            if ($transaction->getOriginal('salary_payout_allocation_id')) {
                throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
            }
            $transaction->guardClinicPayroll();
            if ($transaction->getOriginal('clinic_cash_gel') !== null) {
                throw ValidationException::withMessages(['amount' => __('employees.salary.reverse_only')]);
            }
        });
        static::deleting(function (self $transaction): void {
            if ($transaction->salary_payout_allocation_id) {
                throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
            }
            $transaction->guardClinicPayroll();
            if ($transaction->clinic_cash_gel !== null) {
                throw ValidationException::withMessages(['amount' => __('employees.salary.reverse_only')]);
            }
        });
        static::saving(function (FinanceTransaction $transaction): void {
            $transaction->validateExpenseClassification();
            if ($transaction->clinic_cash_gel !== null) {
                $transaction->funding_source = self::classifyFundingSource(
                    $transaction->clinic_cash_gel,
                    $transaction->israeli_cash_gel,
                );
                $linked = $transaction->employee_salary_settlement_id || $transaction->lab_salary_settlement_id
                    || self::query()->whereKey($transaction->reversal_of_finance_transaction_id)
                        ->where(fn ($query) => $query->whereNotNull('employee_salary_settlement_id')->orWhereNotNull('lab_salary_settlement_id'))->exists();
                if (! $linked || $transaction->currency !== 'GEL' || $transaction->payment_method !== 'cash'
                    || $transaction->cash_source !== 'current_cashier'
                    || (float) $transaction->clinic_cash_gel < 0 || (float) $transaction->israeli_cash_gel < 0
                    || Money::minorUnits($transaction->amount) !== Money::minorUnits($transaction->clinic_cash_gel) + Money::minorUnits($transaction->israeli_cash_gel)) {
                    throw ValidationException::withMessages(['amount' => __('employees.salary.allocation_mismatch')]);
                }
            }
            $transaction->created_by ??= auth()->id();
            $transaction->currency = $transaction->currency ?: Currency::DEFAULT;
            $transaction->payment_method = PaymentMethod::normalize($transaction->payment_method);
            $transaction->description = filled($transaction->description) ? trim($transaction->description) : null;

            if (! isset(self::TYPES[$transaction->type])) {
                throw ValidationException::withMessages(['type' => 'ფინანსური ოპერაციის ტიპი არასწორია.']);
            }
            if (! $transaction->expense_category_id && ! isset(self::CATEGORIES[$transaction->category])) {
                throw ValidationException::withMessages(['category' => 'ფინანსური ოპერაციის კატეგორია არასწორია.']);
            }
            if (! PaymentMethod::isSupported($transaction->payment_method)) {
                throw ValidationException::withMessages(['payment_method' => 'გადახდის მეთოდი არასწორია.']);
            }
            if ((float) $transaction->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'თანხა უნდა იყოს 0-ზე მეტი.']);
            }
            if ($transaction->payment_method === 'cash' && ! isset(self::CASH_SOURCES[$transaction->cash_source])) {
                throw ValidationException::withMessages(['cash_source' => 'აირჩიეთ ნაღდი თანხის წყარო.']);
            }
            if ($transaction->payment_method !== 'cash') {
                $transaction->cash_source = null;
            }
        });
    }

    public function cashboxTransaction(): HasOne
    {
        return $this->hasOne(CashboxTransaction::class);
    }

    private function guardClinicPayroll(): void
    {
        if ($this->getOriginal('payroll_entry_id') || ($this->getOriginal('salary_settlement_id')
            && SalarySettlement::query()->whereKey($this->getOriginal('salary_settlement_id'))->whereNotNull('clinic_payment_method')->exists())) {
            throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
        }
    }

    public function salarySettlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_finance_transaction_id');
    }

    public function employeeSalarySettlement(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalarySettlement::class);
    }

    public function labSalarySettlement(): BelongsTo
    {
        return $this->belongsTo(LabSalarySettlement::class);
    }

    public function israeliCashMovement(): HasOne
    {
        return $this->hasOne(PartnerFinanceTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function classifyFundingSource(mixed $clinic, mixed $israeli): string
    {
        $hasClinic = Money::minorUnits($clinic) > 0;
        $hasIsraeli = Money::minorUnits($israeli) > 0;

        return match (true) {
            $hasClinic && $hasIsraeli => self::FUNDING_MIXED,
            $hasIsraeli => self::FUNDING_ISRAELI,
            default => self::FUNDING_CLINIC,
        };
    }
}
