<?php

namespace App\Filament\Pages;

/** Preserve the old URL while serving the one shared category manager. */
class BankCategories extends ExpenseCategories
{
    protected static bool $shouldRegisterNavigation = false;
}
