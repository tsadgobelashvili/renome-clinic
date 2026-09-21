<?php

namespace App\Models;

use App\Services\LabPartyAutocomplete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class LabCase extends Model
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed'];

    public const SOURCES = ['clinic' => 'Clinic', 'israeli' => 'Israeli', 'external' => 'External'];

    public const MATERIALS = ['pmma' => 'PMMA', 'zircon' => 'Zircon', 'other' => 'Other'];

    protected $fillable = [
        'patient_id', 'doctor_id', 'assistant_employee_id', 'external_doctor_name', 'external_patient_name', 'external_clinic_name', 'case_date', 'source', 'material',
        'quantity', 'shade', 'modeling', 'modeled_by', 'milling_quantity', 'milling_technician', 'milled_by',
        'status', 'exocad_project_reference', 'notes', 'related_case_id', 'case_relationship', 'created_by',
    ];

    protected function casts(): array
    {
        return ['case_date' => 'date', 'quantity' => 'integer', 'milling_quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $case): void {
            if ($case->assistant_employee_id && $case->doctor_id) {
                throw ValidationException::withMessages(['doctor_id' => __('lab.select_one_practitioner')]);
            }
            if ($case->assistant_employee_id && (! $case->exists || $case->isDirty('assistant_employee_id'))
                && ! Employee::query()->labDoctorAssistants()->whereKey($case->assistant_employee_id)->exists()) {
                throw ValidationException::withMessages(['assistant_employee_id' => __('lab.assistant_unavailable')]);
            }
            if (! $case->exists && blank($case->source)) {
                $slug = Patient::query()->whereKey($case->patient_id)->with('patientGroup')->first()?->patientGroup?->slug;
                $case->source = $slug === PatientGroup::ISRAEL_PARTNER_SLUG ? 'israeli' : 'clinic';
            }

            if (! array_key_exists((string) $case->source, self::SOURCES)) {
                throw ValidationException::withMessages([
                    'source' => __('validation.in', ['attribute' => __('lab.source')]),
                ]);
            }

            if ($case->source !== 'external' && blank($case->patient_id)) {
                throw ValidationException::withMessages(['patient_id' => __('lab.patient_required')]);
            }

            if ($case->exists && $case->source === 'israeli' && blank($case->doctor_id) && blank($case->assistant_employee_id)
                && $case->mainWorks()->where('material', 'zircon')->exists()) {
                throw ValidationException::withMessages([
                    'doctor_id' => __('lab.israeli_doctor_required'),
                ]);
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function assistantEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assistant_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modeler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modeled_by');
    }

    public function miller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'milled_by');
    }

    public function relatedCase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_case_id');
    }

    public function relatedCases(): HasMany
    {
        return $this->hasMany(self::class, 'related_case_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(LabWorkItem::class);
    }

    public function additionalWorks(): HasMany
    {
        return $this->hasMany(LabAdditionalWork::class);
    }

    public function mainWorks(): HasMany
    {
        return $this->hasMany(LabMainWork::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getDoctorDisplayAttribute(): string
    {
        if ($this->source === 'external' && filled($this->external_doctor_name)) {
            return $this->external_doctor_name;
        }

        return $this->doctor?->full_name ?: ($this->assistantEmployee
            ? app(LabPartyAutocomplete::class)->practitionerLabel($this->assistantEmployee)
            : ($this->external_doctor_name ?: '—'));
    }

    public function getPatientDisplayAttribute(): string
    {
        return $this->source === 'external'
            ? ($this->external_patient_name ?: ($this->patient?->lab_name ?: '—'))
            : ($this->patient?->lab_name ?: '—');
    }

    public function getModelerDisplayAttribute(): string
    {
        if ($this->modeled_by !== null) {
            return $this->modeler?->employee?->full_name ?: ($this->modeler?->name ?: '—');
        }

        return $this->mainWorks->map(fn (LabMainWork $work) => $work->technicianEmployee)
            ->filter(fn (?Employee $employee): bool => (bool) $employee?->salary_modeler)
            ->unique('id')->map(fn (Employee $employee): string => $employee->full_name)->join(', ') ?: '—';
    }

    public function salaryGroupKey(): int
    {
        return $this->case_relationship === 'same_case' && $this->related_case_id
            ? (int) $this->related_case_id
            : (int) $this->getKey();
    }
}
