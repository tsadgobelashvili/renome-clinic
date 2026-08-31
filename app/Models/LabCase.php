<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabCase extends Model
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed'];

    protected $fillable = ['patient_id', 'doctor_id', 'case_date', 'status', 'exocad_project_reference', 'notes', 'related_case_id', 'case_relationship', 'created_by'];

    protected function casts(): array
    {
        return ['case_date' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function salaryGroupKey(): int
    {
        return $this->case_relationship === 'same_case' && $this->related_case_id
            ? (int) $this->related_case_id
            : (int) $this->getKey();
    }
}
