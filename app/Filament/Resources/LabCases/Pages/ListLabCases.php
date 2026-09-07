<?php

namespace App\Filament\Resources\LabCases\Pages;

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Services\LabPartyAutocomplete;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabCases extends ListRecords
{
    protected static string $resource = LabCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('language')->label(fn (): string => auth()->user()->locale === 'ka' ? 'English' : 'ქართული')
            ->icon('heroicon-o-language')->action(function (): void {
                auth()->user()->update(['locale' => auth()->user()->locale === 'ka' ? 'en' : 'ka']);
                $this->redirect(LabCaseResource::getUrl());
            })];
    }

    public function applyDatePeriod(string $period): void
    {
        abort_unless(in_array($period, ['10', '14', 'month', 'all', 'custom'], true), 422);
        if ($period === 'custom') {
            return;
        }
        $this->tableFilters['toolbar']['from'] = match ($period) {
            '10' => today()->subDays(9)->toDateString(),
            '14' => today()->subDays(13)->toDateString(),
            'month' => today()->subMonthNoOverflow()->toDateString(),
            default => null,
        };
        $this->tableFilters['toolbar']['until'] = $period === 'all' ? null : today()->toDateString();
        $this->updatedTableFilters();
    }

    public function createAction(): CreateAction
    {
        return CreateAction::make('create')
            ->label(__('lab.create_short'))
            ->extraAttributes(['class' => 'renome-visits-toolbar__create'])
            ->modalHeading(__('lab.new_work'))
            ->modalDescription(fn (): string => today()->format('d.m.Y'))
            ->modalWidth('7xl')
            ->modalSubmitActionLabel(__('lab.create'))
            ->modalCancelActionLabel(__('lab.cancel'))
            ->createAnother()
            ->createAnotherAction(fn (Action $action): Action => $action->label(__('lab.create_another'))->color('gray'))
            ->forceRenderAfterCreateAnother()
            ->mutateDataUsing(function (array $data): array {
                $patient = app(LabPartyAutocomplete::class)->resolvePatientForLab(
                    filled($data['patient_id'] ?? null) ? (int) $data['patient_id'] : null,
                    $data['patient_entry'] ?? null,
                    (string) ($data['source'] ?? 'clinic'),
                );
                unset($data['patient_entry']);

                return [...$data, 'patient_id' => $patient->getKey(), 'created_by' => auth()->id()];
            });
    }
}
