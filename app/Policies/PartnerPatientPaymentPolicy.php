<?php

namespace App\Policies;

class PartnerPatientPaymentPolicy extends ClinicalOperationsPolicy
{
    public function update(\App\Models\User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return $user->canManageOwnerModules();
    }

    protected function canDestroy(\App\Models\User $user): bool
    {
        return $user->canManageOwnerModules();
    }

    public function forceDelete(\App\Models\User $user, \Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(\App\Models\User $user): bool
    {
        return false;
    }
}
