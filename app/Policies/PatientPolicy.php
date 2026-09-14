<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy extends ClinicalOperationsPolicy
{
    public function exportHistory(User $user, Patient $record): bool
    {
        return $this->view($user, $record);
    }
}
