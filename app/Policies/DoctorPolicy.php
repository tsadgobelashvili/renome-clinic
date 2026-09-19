<?php

namespace App\Policies;

use App\Models\User;

class DoctorPolicy extends ClinicalOperationsPolicy
{
    public function manageCompensation(User $user): bool
    {
        return $user->canManageOwnerModules();
    }
}
