<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/** Preserve old bookmarks without maintaining a second bank interface. */
class BogTransactions extends Page
{
    protected string $view = 'filament.pages.bog-transactions';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_active && auth()->user()?->isOwner());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->redirect(Bank::getUrl());
    }
}
