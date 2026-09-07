<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabAdditionalWork extends Model
{
    protected $fillable = ['lab_case_id', 'work_type', 'quantity', 'technician_id', 'technician', 'note'];

    public function labCase(): BelongsTo
    {
        return $this->belongsTo(LabCase::class);
    }

    public function technicianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }
}
