<?php

namespace App\Filament\Resources\TreatmentEstimates\Actions;

use App\Filament\Resources\TreatmentEstimates\Schemas\TreatmentEstimateForm;
use App\Models\Patient;
use App\Models\TreatmentEstimate;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
        ?Closure $afterSaved = null,
    ): Action {
        $createAction = Action::make($name)
            ->label('+ გეგმა')
            ->icon(Heroicon::Calculator)
            ->record(fn (): null => null)
            ->model(TreatmentEstimate::class)
            ->modalHeading('მკურნალობის გეგმა')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('შექმნა')
            ->databaseTransaction()
            ->schema([
                Hidden::make('mode')->default($initialMode)->dehydrated(false),
                Hidden::make('selected_estimate_id')->dehydrated(false),
                Actions::make([
                    Action::make('showCreatePlanForm')
                        ->label('+ ახალი გეგმა')
                        ->icon(Heroicon::Plus)
                        ->action(fn (Set $set): mixed => $set('mode', 'create')),
                    Action::make('cancelPlanEdit')
                        ->label('გაუქმება')
                        ->color('gray')
                        ->actionJs('$wire.cancelTreatmentPlanEditInModal()'),
                ])->visible(fn (Get $get): bool => in_array($get('mode'), ['list', 'edit'], true)),
                View::make('filament.resources.patients.treatment-plans-slide-over')
                    ->viewData(fn (Action $action): array => [
                        'patient' => self::loadPlans(self::resolvePatientId($action, $patientId)),
                    ])
                    ->visible(fn (Get $get): bool => $get('mode') === 'list'),
                Group::make(TreatmentEstimateForm::components(hidePatient: true))
                    ->columns(['default' => 1, 'md' => 2])
                    ->visible(fn (Get $get): bool => in_array($get('mode'), ['create', 'edit'], true)),
            ])
            ->fillForm(fn (Action $action): array => [
                'mode' => $initialMode,
                'selected_estimate_id' => null,
                'patient_id' => self::resolvePatientId($action, $patientId),
                'doctor_id' => self::resolvePrefillDoctorId($action, $patientId, $doctorId),
                'estimate_date' => now()->toDateString(),
            ])
            ->mutateFormDataUsing(function (array $data, Action $action) use ($patientId): array {
                $data['patient_id'] = self::resolvePatientId($action, $patientId);
                $data['visit_id'] = null;

                return $data;
            })
            ->action(function (array $data, Action $action, Schema $schema) use ($afterSaved, $doctorId, $initialMode, $patientId): void {
                $rawData = $action->getRawData();
                $record = $action->getRecord();

                if (($rawData['mode'] ?? null) === 'edit' && filled($rawData['selected_estimate_id'] ?? null)) {
                    $patientKey = self::resolvePatientId($action, $patientId);
                    $estimateKey = (int) $rawData['selected_estimate_id'];

                    if (! $record instanceof TreatmentEstimate
                        || (int) $record->getKey() !== $estimateKey
                        || (int) $record->patient_id !== $patientKey) {
                        $record = TreatmentEstimate::query()
                            ->where('patient_id', $patientKey)
                            ->findOrFail($estimateKey);
                    }
                    unset($data['patient_id'], $data['visit_id']);
                    $record->update($data);
                } else {
                    $record = new TreatmentEstimate;
                    $record->fill($data);
                    $record->save();
                }

                $action->record($record);
                self::bindSchemaToRecord($schema, $record);
                $schema->saveRelationships();

                if ($afterSaved !== null) {
                    $action->evaluate($afterSaved, ['estimate' => $record]);
                }

                $wasEditing = ($rawData['mode'] ?? null) === 'edit';
                Notification::make()->success()->title($wasEditing
                    ? 'მკურნალობის გეგმა განახლდა.'
                    : 'მკურნალობის გეგმა შეიქმნა.')->send();

                if ($initialMode !== 'list') {
                    return;
                }

                self::returnToList($action, $patientId, $doctorId);
                $action->halt();
            });

        $createAction->modalSubmitAction(fn (Action $action): Action => $action
            ->label('შენახვა')
            ->visible(fn (): bool => in_array($createAction->getRawData()['mode'] ?? $initialMode, ['create', 'edit'], true)));

        return $createAction;
    }

    private static function resolvePatientId(Action $action, int|Closure $patientId): int
    {
        return (int) $action->evaluate($patientId);
    }

    private static function resolveOptionalId(Action $action, int|Closure|null $id): ?int
    {
        $resolved = $id instanceof Closure ? $action->evaluate($id) : $id;

        return filled($resolved) ? (int) $resolved : null;
    }

    public static function preferredDoctorIdForPatient(int $patientId): ?int
    {
        $patient = Patient::query()->with(['doctors' => fn ($query) => $query
            ->where('is_active', true)
            ->orderByDesc('patient_doctor.is_primary')
            ->orderBy('doctors.id')])
            ->find($patientId);

        if (! $patient) {
            return null;
        }

        return $patient->doctors
            ->map(function ($doctor): array {
                $specialty = mb_strtolower(trim(implode(' ', array_filter([
                    $doctor->specialty,
                    $doctor->pivot?->role,
                ]))));

                $specialtyPriority = match (true) {
                    str_contains($specialty, 'implant'), str_contains($specialty, 'იმპლანტ') => 0,
                    str_contains($specialty, 'orthoped'), str_contains($specialty, 'ორთოპედ') => 1,
                    default => null,
                };

                return [
                    'doctor_id' => (int) $doctor->getKey(),
                    'is_primary' => (bool) $doctor->pivot?->is_primary,
                    'specialty_priority' => $specialtyPriority,
                ];
            })
            ->filter(fn (array $doctor): bool => $doctor['specialty_priority'] !== null)
            ->unique('doctor_id')
            ->sortBy(fn (array $doctor): array => [
                $doctor['is_primary'] ? 0 : 1,
                $doctor['specialty_priority'],
                $doctor['doctor_id'],
            ])
            ->value('doctor_id');
    }

    private static function resolvePrefillDoctorId(
        Action $action,
        int|Closure $patientId,
        int|Closure|null $doctorId,
    ): ?int {
        return self::resolveOptionalId($action, $doctorId)
            ?? self::preferredDoctorIdForPatient(self::resolvePatientId($action, $patientId));
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

    private static function returnToList(Action $action, int|Closure $patientId, int|Closure|null $doctorId): void
    {
        $action->record(null);
        $livewire = $action->getLivewire();
        $schema = $action->getSchemaContainer();

        if (! $schema instanceof Schema) {
            $schemaName = $livewire->getMountedActionSchemaName();
            $schema = filled($schemaName) ? $livewire->getSchema($schemaName) : null;
        }

        if (! $schema instanceof Schema) {
            return;
        }

        $schema->model(TreatmentEstimate::class)->fill([
            'mode' => 'list',
            'selected_estimate_id' => null,
            'patient_id' => self::resolvePatientId($action, $patientId),
            'doctor_id' => self::resolvePrefillDoctorId($action, $patientId, $doctorId),
            'estimate_date' => now()->toDateString(),
        ]);
    }

    private static function bindSchemaToRecord(Schema $schema, TreatmentEstimate $record): void
    {
        $schema->model($record);

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $component->model($record);
            self::clearRepeaterCaches($component->getChildSchemas(withHidden: true));
        }
    }

    private static function clearRepeaterCaches(array $schemas): void
    {
        foreach ($schemas as $schema) {
            foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
                if ($component instanceof Repeater) {
                    $component->clearCachedExistingRecords();
                }

                self::clearRepeaterCaches($component->getChildSchemas(withHidden: true));
            }
        }
    }
}
