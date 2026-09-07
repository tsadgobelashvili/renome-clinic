<?php

namespace App\Filament\Resources\Patients\Pages\Concerns;

use App\Filament\Resources\TreatmentEstimates\Actions\CreateTreatmentEstimateAction;
use App\Models\Patient;
use App\Models\TreatmentEstimate;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;

trait InteractsWithPatientTreatmentPlanModal
{
    public function editTreatmentPlanInModal(int $estimateId): void
    {
        /** @var Patient $patient */
        $patient = $this->getRecord();
        $estimate = $patient->treatmentEstimates()
            ->with(['doctor', 'options.items', 'options.stages.items'])
            ->findOrFail($estimateId);

        $action = $this->getMountedAction();
        $schemaName = $this->getMountedActionSchemaName();
        $schema = filled($schemaName) ? $this->getSchema($schemaName) : null;

        if (! $action instanceof Action || ! $schema instanceof Schema || $action->getName() !== 'treatmentPlans') {
            return;
        }

        $action->record($estimate);
        $schema->model($estimate);
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $component->model($estimate);
        }
        self::clearRepeaterRelationshipCaches($schema);
        $schema->fill([
            ...$estimate->attributesToArray(),
            'doctor_id' => $estimate->doctor_id
                ?? CreateTreatmentEstimateAction::preferredDoctorIdForPatient($patient->getKey()),
            'mode' => 'edit',
            'selected_estimate_id' => $estimate->getKey(),
        ]);
        $schema->loadStateFromRelationships(shouldHydrate: true);
    }

    public function cancelTreatmentPlanEditInModal(): void
    {
        /** @var Patient $patient */
        $patient = $this->getRecord();
        $action = collect($this->getMountedActions())
            ->first(fn ($mountedAction): bool => $mountedAction instanceof Action
                && $mountedAction->getName() === 'treatmentPlans');
        $schema = $action?->getSchemaContainer() ?? $this->getSchema('mountedActionSchema0');

        if (! $action instanceof Action || ! $schema instanceof Schema) {
            return;
        }

        $action->record(null);
        $schema->model(TreatmentEstimate::class)->fill([
            'mode' => 'list',
            'selected_estimate_id' => null,
            'patient_id' => $patient->getKey(),
            'doctor_id' => CreateTreatmentEstimateAction::preferredDoctorIdForPatient($patient->getKey()),
            'estimate_date' => now()->toDateString(),
        ]);
    }

    private static function clearRepeaterRelationshipCaches(Schema $schema): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Repeater) {
                $component->clearCachedExistingRecords();
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                self::clearRepeaterRelationshipCaches($childSchema);
            }
        }
    }
}
