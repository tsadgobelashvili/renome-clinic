<?php

namespace App\Support;

use App\Filament\Pages;
use App\Models\User;
use Filament\Auth\Pages\EditProfile;

/** Explicit page capabilities: unlisted classes are denied, including subclasses. */
class PanelPageAccess
{
    public static function allows(User $user, string $page): bool
    {
        return match ($page) {
            Pages\Dashboard::class,
            Pages\Cashbox::class,
            Pages\DoctorCompensation::class => $user->canManageClinicOperations(),
            Pages\Bank::class,
            Pages\BankCategories::class,
            Pages\BankRules::class,
            Pages\BogTransactions::class,
            Pages\ExpenseCategories::class,
            Pages\ExternalLabOrders::class,
            Pages\Finance::class,
            Pages\FinanceOpeningBalances::class,
            Pages\FinanceReports::class,
            Pages\FullDiscountStatistics::class,
            Pages\LabSalaries::class,
            Pages\ProfitLoss::class => $user->canManageOwnerModules(),
            EditProfile::class => $user->canManageClinicOperations() || $user->canAccessLab(),
            default => false,
        };
    }
}
