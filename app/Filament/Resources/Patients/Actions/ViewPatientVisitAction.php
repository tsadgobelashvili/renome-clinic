<?php

namespace App\Filament\Resources\Patients\Actions;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Support\PatientPaymentHistory;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Patient;
use App\Models\Visit;
use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

final class ViewPatientVisitAction
{
    public static function make(Patient $patient, Closure $visitId): Action
    {
        $loaded = null;
        $resolve = function (Model $record) use ($patient, $visitId, &$loaded): Visit {
            abort_unless(PatientResource::canView($patient), 403);
            if ($loaded === null || (string) $loaded->getKey() !== (string) $visitId($record)) {
                $loaded = $patient->visits()->withCancelled()->findOrFail($visitId($record));
                abort_unless(VisitResource::canView($loaded), 403);
                $loaded->load(['patient.patientGroup', 'doctor', 'payments', 'treatmentCaseItems.treatmentCase',
                    'treatmentCaseItems.directExpenses.expenseDirection', 'treatmentCaseItems.directExpenses.expenseType']);
            }

            return $loaded;
        };

        return Action::make('visitDetails')->label(__('patient-profile.visit_details'))
            ->modalHeading(__('patient-profile.visit_details'))->modalWidth('5xl')
            ->modalSubmitAction(false)->modalCancelActionLabel(__('patient-profile.close'))
            ->stickyModalHeader()->stickyModalFooter()
            ->modalContent(function (Model $record) use ($resolve, $patient) {
                $visit = $resolve($record);

                return view('filament.resources.patients.visit-details', [
                    'visit' => $visit,
                    'payments' => PatientPaymentHistory::query($patient)->without('visit')->where('payments.visit_id', $visit->getKey())
                        ->orderByDesc('payment_date')->orderByDesc('id')->get(),
                ]);
            })
            ->extraModalFooterActions(fn (Model $record): array => VisitResource::canEdit($resolve($record)) ? [
                Action::make('editVisit')->label(__('patient-profile.edit'))
                    ->url(VisitResource::getUrl('edit', ['record' => $resolve($record)])),
            ] : []);
    }
}
