<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use Filament\Pages\Page;

/** Preserve old bookmarks without maintaining a second bank interface. */
class BogTransactions extends Page
{
    use AuthorizesPageAccess;

    protected string $view = 'filament.pages.bog-transactions';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->redirect(Bank::getUrl());
    }
}
