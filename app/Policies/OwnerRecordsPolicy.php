<?php

namespace App\Policies;

use App\Models\User;

abstract class OwnerRecordsPolicy extends RecordPolicy
{
    protected function canManage(User $user): bool
    {
        return $user->canManageOwnerModules();
    }
}
