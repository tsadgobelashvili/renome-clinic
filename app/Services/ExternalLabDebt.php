<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\ExternalLabCharge;
use App\Models\LabMainWork;
use App\Models\SalaryPayout;
use Illuminate\Validation\ValidationException;

class ExternalLabDebt
{
    public function outstanding(int $doctorId)
    {
        return ExternalLabCharge::where('doctor_id', $doctorId)
            ->whereRaw('amount > COALESCE((SELECT SUM(d.amount) FROM external_lab_deductions d WHERE d.external_lab_charge_id = external_lab_charges.id), 0)')
            ->with('work.labCase')
            ->withSum('deductions as deducted', 'amount')->orderBy('id')->get()
            ->filter(fn ($charge) => $charge->remaining() > 0)->values();
    }

    public function balance(int $doctorId): float
    {
        return round((float) ExternalLabCharge::where('doctor_id', $doctorId)
            ->selectRaw('COALESCE(SUM(amount - COALESCE((SELECT SUM(d.amount) FROM external_lab_deductions d WHERE d.external_lab_charge_id = external_lab_charges.id), 0)), 0) as balance')
            ->value('balance'), 2);
    }

    public function sync(LabMainWork $work): void
    {
        $case = $work->labCase()->first();
        $doctorId = $case?->source === 'external' ? $case->external_billing_doctor_id : null;
        $charge = ExternalLabCharge::where('lab_main_work_id', $work->id)->first();
        if (! $doctorId) {
            return;
        }
        $doctor = Doctor::findOrFail($doctorId);
        $rate = $charge?->unit_rate ?? $doctor->{'external_lab_'.$work->material.'_rate'};
        if (! in_array($work->material, ['zircon', 'pmma']) || $rate === null) {
            throw ValidationException::withMessages(['external_billing_doctor_id' => 'ექიმის პროფილში მიუთითეთ ამ მასალის გარე სამუშაოების ტარიფი.']);
        }
        $amount = round((float) $rate * $work->quantity, 2);
        if ($charge && $charge->deductions()->exists()) {
            return;
        }
        ExternalLabCharge::updateOrCreate(['lab_main_work_id' => $work->id], [
            'doctor_id' => $doctorId, 'unit_rate' => $rate, 'amount' => $amount,
        ]);
    }

    // The caller holds the doctor lock and the surrounding payout transaction.
    public function deduct(SalaryPayout $payout, float $amount): void
    {
        $left = (int) round($amount * 100);
        foreach ($this->outstanding($payout->settlement->doctor_id) as $charge) {
            $take = min($left, (int) round($charge->remaining() * 100));
            if ($take > 0) {
                $charge->deductions()->create(['salary_payout_id' => $payout->id, 'amount' => $take / 100]);
                $left -= $take;
            }
            if ($left === 0) {
                break;
            }
        }
        if ($left !== 0) {
            throw ValidationException::withMessages(['deduct_external' => 'გარე სამუშაოების ნაშთი შეიცვალა. განაახლეთ ფორმა.']);
        }
    }
}
