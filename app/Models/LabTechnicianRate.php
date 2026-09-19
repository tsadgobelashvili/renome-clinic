<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveTechnicianRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTechnicianRate extends Model
{
    use HasEffectiveTechnicianRate;

    protected $fillable = ['technician_id', 'work_type', 'component_type', 'rate_per_unit', 'is_active', 'effective_from'];

    protected function casts(): array
    {
        return ['rate_per_unit' => 'decimal:2', 'is_active' => 'boolean', 'effective_from' => 'date'];
    }

    protected function rateIdentityColumns(): array
    {
        return ['technician_id', 'work_type', 'component_type'];
    }

    public function deletionProtected(): bool
    {
        $until = $this->nextEffectiveDate();

        return LabWorkItem::query()->where($this->only($this->rateIdentityColumns()))
            ->where('work_date', '>=', $this->effective_from->toDateString())
            ->when($until, fn ($query) => $query->where('work_date', '<', $until))->exists();
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
