<?php

namespace App\Models;

use App\Services\ExternalLabDebt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LabMainWork extends Model
{
    protected $fillable = ['lab_case_id', 'material', 'quantity', 'shade', 'sort_order', 'technician_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $work): void {
            $case = $work->labCase;
            $charge = ExternalLabCharge::where('lab_main_work_id', $work->id)->first();
            if ($charge && $work->isDirty(['material', 'quantity', 'lab_case_id'])) {
                if ($charge->deductions()->exists() || $work->isDirty(['material', 'lab_case_id'])) {
                    throw ValidationException::withMessages(['mainWorks' => 'დარიცხული გარე სამუშაოს შეცვლა შეუძლებელია.']);
                }
            }
            if ($case?->source === 'external' && $case->external_billing_doctor_id) {
                $doctor = $case->externalBillingDoctor;
                if (! in_array($work->material, ['zircon', 'pmma']) || ($charge?->unit_rate ?? $doctor?->{'external_lab_'.$work->material.'_rate'}) === null) {
                    throw ValidationException::withMessages(['mainWorks' => 'ექიმის პროფილში მიუთითეთ გარე სამუშაოების ტარიფი.']);
                }
            }
            if ($work->material === 'zircon' && $case?->source === 'israeli' && blank($case->doctor_id) && blank($case->assistant_employee_id)) {
                throw ValidationException::withMessages([
                    'doctor_id' => __('lab.israeli_doctor_required'),
                ]);
            }
        });
        static::saved(function (self $work) {
            app(ExternalLabDebt::class)->sync($work);
            $work->syncLegacySummary();
        });
        static::deleting(function (self $work) {
            $charge = ExternalLabCharge::where('lab_main_work_id', $work->id)->first();
            if ($charge?->deductions()->exists()) {
                throw ValidationException::withMessages(['mainWorks' => 'დაქვითული სამუშაო ვერ წაიშლება.']);
            }
            $charge?->delete();
        });
        static::deleted(fn (self $work) => $work->syncLegacySummary());
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $doctorId = LabCase::whereKey($this->lab_case_id)->value('external_billing_doctor_id');
            if ($doctorId) {
                Doctor::whereKey($doctorId)->lockForUpdate()->firstOrFail();
            }

            return parent::save($options);
        });
    }

    public function delete()
    {
        return DB::transaction(function () {
            $doctorId = LabCase::whereKey($this->lab_case_id)->value('external_billing_doctor_id');
            if ($doctorId) {
                Doctor::whereKey($doctorId)->lockForUpdate()->firstOrFail();
            }

            return parent::delete();
        });
    }

    public function labCase(): BelongsTo
    {
        return $this->belongsTo(LabCase::class);
    }

    public function technicianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function externalCharge(): HasOne
    {
        return $this->hasOne(ExternalLabCharge::class);
    }

    public function salarySettlementItem(): HasOne
    {
        return $this->hasOne(SalarySettlementItem::class);
    }

    public function linkedVisitTreatmentCase(): HasOne
    {
        return $this->hasOne(VisitTreatmentCase::class);
    }

    private function syncLegacySummary(): void
    {
        $case = $this->labCase;
        $first = $case?->mainWorks()->orderBy('sort_order')->orderBy('id')->first();

        $case?->updateQuietly([
            'material' => $first?->material,
            'quantity' => $first?->quantity,
            'shade' => $first?->shade,
        ]);
    }
}
