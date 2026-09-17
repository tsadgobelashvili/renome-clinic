<?php

namespace App\Filament\Pages\Concerns;

use App\Services\Bank\BogAccountIdentifier;
use App\Services\BogBusinessApiService;
use Livewire\Attributes\Locked;
use RuntimeException;

/** One live account snapshot shared by Bank and Finance; never reads statement balances. */
trait HasBogCurrentBalance
{
    #[Locked]
    public ?array $bogBalance = null;

    #[Locked]
    public bool $bogBalanceFailed = false;

    public function refreshBogBalance(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $amount = app(BogBusinessApiService::class)->currentBalance();
            $this->bogBalance = ['bank' => 'BOG', 'reported_balance' => $amount,
                'currency' => strtoupper(config('services.bog.account_currency')),
                'account_identifier' => BogAccountIdentifier::normalize(config('services.bog.account_number')),
                'fetched_at' => now()->toDateTimeString()];
            $this->bogBalanceFailed = false;
        } catch (RuntimeException) {
            $this->bogBalance = null;
            $this->bogBalanceFailed = true;
        }
    }
}
