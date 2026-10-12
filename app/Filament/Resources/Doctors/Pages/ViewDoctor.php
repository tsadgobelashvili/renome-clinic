<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Concerns\InteractsWithDoctorSalary;
use App\Filament\Concerns\RedirectsToCanonicalPersonUrl;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Services\IsraeliDoctorPayoutHistory;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDoctor extends ViewRecord
{
    use InteractsWithDoctorSalary;
    use RedirectsToCanonicalPersonUrl;

    protected static string $resource = DoctorResource::class;

    public function israeliPayoutDetailsAction(): Action
    {
        return Action::make('israeliPayoutDetails')->label(__('israeli-payout-history.details'))
            ->visible(fn (): bool => auth()->user()?->canViewSalaryHistory() ?? false)
            ->modalHeading(__('israeli-payout-history.title'))->modalWidth('7xl')
            ->modalSubmitAction(false)->modalCancelActionLabel(__('israeli-payout-history.close'))
            ->modalContent(function (array $arguments) {
                abort_unless(auth()->user()?->canViewSalaryHistory(), 403);
                $entry = app(IsraeliDoctorPayoutHistory::class)->find($this->getRecord()->id, (string) ($arguments['key'] ?? ''));

                return view('filament.resources.doctors.israeli-payout-details', compact('entry'));
            });
    }

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
