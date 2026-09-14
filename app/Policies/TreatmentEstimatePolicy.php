<?php

namespace App\Policies;

use App\Models\TreatmentEstimate;
use App\Models\User;

class TreatmentEstimatePolicy extends ClinicalOperationsPolicy
{
    public function export(User $user, TreatmentEstimate $record): bool
    {
        return $this->view($user, $record);
    }
}
