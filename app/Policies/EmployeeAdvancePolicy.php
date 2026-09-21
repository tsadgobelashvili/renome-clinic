<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EmployeeAdvancePolicy extends OwnerRecordsPolicy
{
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    protected function canDestroy(User $user): bool
    {
        return false;
    }
}
