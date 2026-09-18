<?php

namespace App\Policies;

use App\Models\User;

class LabCasePolicy extends RecordPolicy
{
    protected function canManage(User $user): bool
    {
        // One shared operational queue, including cases created by other users.
        return $user->canAccessLab();
    }

    protected function canDestroy(User $user): bool
    {
        return $user->canManageOwnerModules();
    }
}
