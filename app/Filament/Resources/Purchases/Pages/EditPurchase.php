<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Actions\LinkPurchaseToAdvance;
use App\Filament\Pages\Bank;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\PurchaseCashPayment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected \Filament\Support\Enums\Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected ?bool $hasDatabaseTransactions = true;

    #[Locked]
    public ?int $cashPostingToReverse = null;

    #[Locked]
    public string $cashConfirmationAmount = '0';

    public function cashPaymentAction(): Action
    {
        return Action::make('cashPayment')->label('ქეში')->requiresConfirmation()
            ->visible(fn () => auth()->user()?->isOwner() && $this->record->source === 'rs')
            ->mountUsing(function (): void {
                $this->authorizeAccess();
                $purchase = $this->record->fresh();
                $posting = $purchase->cashExpense()->first();
                $this->cashPostingToReverse = $posting?->id;
                $this->cashConfirmationAmount = (string) ($posting?->amount ?? $purchase->total_amount);
            })
            ->modalHeading(fn () => $this->cashPostingToReverse ? 'ქეშით გადახდის გაუქმება' : 'ქეშით გადახდა')
            ->modalDescription(fn () => $this->cashPostingToReverse
                ? 'დაბრუნდეს '.number_format((float) $this->cashConfirmationAmount, 2).' GEL კლინიკის ქეშში? თავდაპირველი გადახდა დარჩება ისტორიაში.'
                : 'გამოაკლდეს '.number_format((float) $this->cashConfirmationAmount, 2).' GEL კლინიკის ქეშს?')
            ->modalSubmitActionLabel('დადასტურება')
            ->action(function (): void {
                $this->authorizeAccess();
                $service = app(PurchaseCashPayment::class);
                if ($this->cashPostingToReverse) {
                    $service->reverse($this->record->id, $this->cashPostingToReverse, auth()->user());
                } else {
                    $service->post($this->record->id, auth()->user(), $this->cashConfirmationAmount);
                }
                $this->record->refresh();
                $this->data['cash_paid'] = $this->record->cashExpense()->exists();
                Notification::make()->success()->title('შენახულია')->send();
            });
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('შენახვა');
    }

    protected function getRedirectUrl(): string
    {
        // Reuse Filament's captured referrer, retaining list filters/search/pagination.
        $previous = $this->previousUrl ?? '';
        if (! preg_match('/[\x00-\x20\\\\]/', $previous)) {
            foreach ([$this->getResourceUrl('index'), PurchaseResource::getUrl('items'), Bank::getUrl()] as $url) {
                if ($previous === $url || str_starts_with($previous, $url.'?')) {
                    return $previous;
                }
            }
        }

        return $this->getResourceUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [LinkPurchaseToAdvance::make(), DeleteAction::make()
            ->disabled(fn () => $this->record->cashPostings()->exists() || $this->record->advanceSettlement()->exists())];
    }
}
