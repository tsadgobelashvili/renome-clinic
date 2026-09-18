<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Standard model abilities, with the permitted audience defined by each policy. */
abstract class RecordPolicy
{
    abstract protected function canManage(User $user): bool;

    protected function canDestroy(User $user): bool
    {
        return $this->canManage($user);
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
        return $this->canDestroy($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canDestroy($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->canDestroy($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canDestroy($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->canDestroy($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canDestroy($user);
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function reorder(User $user): bool
    {
        return $this->canManage($user);
    }
}
