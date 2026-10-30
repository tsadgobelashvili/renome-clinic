<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Classification aliases only: never replaces a visit's clinical relationship. */
class ProcedureCatalogMapping extends Model
{
    protected $fillable = ['normalized_name', 'treatment_case_id'];
}
