<?php

namespace App\Policies;

use App\Models\User;

/** Shared operational access for clinical records; not Finance or Laboratory. */
abstract class ClinicalOperationsPolicy extends RecordPolicy
{
    protected function canManage(User $user): bool
    {
        return $user->canManageClinicOperations();
    }
}
