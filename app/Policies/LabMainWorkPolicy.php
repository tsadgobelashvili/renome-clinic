<?php

namespace App\Policies;

use App\Models\LabMainWork;
use App\Models\User;

class LabMainWorkPolicy
{
    public function updatePayableQuantity(User $user, LabMainWork $work): bool
    {
        return $user->is_active && $user->isOwner();
    }
}
