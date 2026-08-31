<?php

namespace App\Filament\Resources\TreatmentEstimates\Actions;

use App\Filament\Resources\TreatmentEstimates\Schemas\TreatmentEstimateForm;
use App\Models\Patient;
use App\Models\TreatmentEstimate;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class CreateTreatmentEstimateAction
{
    public static function make(
        int|Closure $patientId,
        int|Closure|null $doctorId = null,
        string $initialMode = 'create',
        string $name = 'createEstimate',
    ): CreateAction {
        $createAction = CreateAction::make($name)
            ->label('+ გეგმა')
            ->icon(Heroicon::Calculator)
            ->record(fn (): null => null)
            ->model(TreatmentEstimate::class)
            ->modalHeading('მკურნალობის გეგმა')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('შექმნა')
            ->createAnother(false)
            ->databaseTransaction()
            ->schema([
                Hidden::make('mode')->default($initialMode)->dehydrated(false),
                Actions::make([
                    Action::make('showCreatePlanForm')
                        ->label('+ ახალი გეგმა')
                        ->icon(Heroicon::Plus)
                        ->action(fn (Set $set): mixed => $set('mode', 'create')),
                ])->visible(fn (Get $get): bool => $get('mode') === 'list'),
                View::make('filament.resources.patients.treatment-plans-slide-over')
                    ->viewData(fn (CreateAction $action): array => [
                        'patient' => self::loadPlans(self::resolvePatientId($action, $patientId)),
                    ])
                    ->visible(fn (Get $get): bool => $get('mode') === 'list'),
                Group::make(TreatmentEstimateForm::components(hidePatient: true))
                    ->visible(fn (Get $get): bool => $get('mode') === 'create'),
            ])
            ->fillForm(fn (CreateAction $action): array => [
                'mode' => $initialMode,
                'patient_id' => self::resolvePatientId($action, $patientId),
                'doctor_id' => self::resolveOptionalId($action, $doctorId),
                'estimate_date' => now()->toDateString(),
            ])
            ->mutateFormDataUsing(function (array $data, CreateAction $action) use ($patientId): array {
                $data['patient_id'] = self::resolvePatientId($action, $patientId);
                $data['visit_id'] = null;

                return $data;
            })
            ->after(function (CreateAction $action) use ($doctorId, $initialMode, $patientId): void {
                if ($initialMode !== 'list') {
                    return;
                }

                Notification::make()->success()->title('მკურნალობის გეგმა შეიქმნა.')->send();
                $action->record(null);
                $livewire = $action->getLivewire();
                $schemaName = $livewire->getMountedActionSchemaName();
                $schema = filled($schemaName) ? $livewire->getSchema($schemaName) : null;

                if (! $schema instanceof Schema) {
                    return;
                }

                $schema->model(TreatmentEstimate::class)->fill([
                    'mode' => 'list',
                    'patient_id' => self::resolvePatientId($action, $patientId),
                    'doctor_id' => self::resolveOptionalId($action, $doctorId),
                    'estimate_date' => now()->toDateString(),
                ]);
                $action->halt();
            });

        $createAction->modalSubmitAction(fn (Action $action): Action => $action
            ->label('შენახვა')
            ->visible(fn (): bool => ($createAction->getData()['mode'] ?? $initialMode) === 'create'));

        return $createAction;
    }

    private static function resolvePatientId(CreateAction $action, int|Closure $patientId): int
    {
        return (int) $action->evaluate($patientId);
    }

    private static function resolveOptionalId(CreateAction $action, int|Closure|null $id): ?int
    {
        $resolved = $id instanceof Closure ? $action->evaluate($id) : $id;

        return filled($resolved) ? (int) $resolved : null;
    }

    private static function loadPlans(int $patientId): Patient
    {
        return Patient::query()->with([
            'treatmentEstimates' => fn ($query) => $query
                ->with(['doctor', 'options.items', 'options.stages.items'])
                ->orderByDesc('estimate_date')
                ->orderByDesc('id'),
        ])->findOrFail($patientId);
    }
}
