<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared operational access for clinical records; not Finance or Laboratory.
 */
abstract class ClinicalOperationsPolicy
{
    protected function canManage(User $user): bool
    {
        return $user->is_active && ($user->isOwner() || $user->isAdministrator());
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManage($user);
    }
}
