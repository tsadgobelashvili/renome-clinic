<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reporting aliases only: never replaces a visit's clinical/payroll relationship. */
class ProcedureCatalogMapping extends Model
{
    protected $fillable = ['normalized_name', 'treatment_case_id'];
}
