<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTechnicianRate extends Model
{
    protected $fillable = ['technician_id', 'work_type', 'component_type', 'rate_per_unit', 'is_active'];

    protected function casts(): array
    {
        return ['rate_per_unit' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
