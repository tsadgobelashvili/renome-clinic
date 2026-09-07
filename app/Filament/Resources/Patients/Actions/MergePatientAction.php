<?php

namespace App\Filament\Resources\Patients\Actions;

use App\Models\Patient;
use App\Services\PatientMergeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class MergePatientAction
{
    public static function make(Patient $primary): Action
    {
        return Action::make('mergePatient')
            ->label('Merge patient')
            ->icon('heroicon-o-arrows-right-left')
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->isOwner() || auth()->user()?->isAdministrator())
            ->modalHeading('Merge patient')
            ->modalDescription('All history will be moved to the primary patient in one transaction. This cannot be partially completed.')
            ->modalSubmitActionLabel('Confirm merge')
            ->requiresConfirmation()
            ->schema([
                Placeholder::make('primary_patient')
                    ->label('Primary patient (will remain)')
                    ->content(self::patientLabel($primary)),
                Select::make('duplicate_patient_id')
                    ->label('Duplicate patient (will be removed)')
                    ->options(fn (): array => Patient::query()
                        ->whereKeyNot($primary->getKey())
                        ->latest('id')
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (Patient $patient): array => [$patient->getKey() => self::patientLabel($patient)])
                        ->all())
                    ->getSearchResultsUsing(fn (string $search): array => Patient::query()
                        ->whereKeyNot($primary->getKey())
                        ->when(filled($search), fn (Builder $query): Builder => $query->searchForClinic($search))
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (Patient $patient): array => [$patient->getKey() => self::patientLabel($patient)])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($patient = Patient::find($value)) ? self::patientLabel($patient) : null)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->required(),
                Placeholder::make('duplicate_summary')
                    ->label('Selected duplicate')
                    ->content(function (Get $get): HtmlString {
                        $patient = Patient::find($get('duplicate_patient_id'));

                        return new HtmlString($patient
                            ? e(self::patientLabel($patient)).'<br><span class="text-sm text-gray-500">This record will no longer appear separately after the merge.</span>'
                            : '<span class="text-sm text-gray-500">Search and select the duplicate record.</span>');
                    }),
                Toggle::make('copy_missing_fields')
                    ->label('Copy useful fields that are empty on the primary patient')
                    ->helperText('Phone, birth date, personal ID and Latin/display names are copied only when the primary value is empty. Notes from both records are preserved.')
                    ->default(true),
            ])
            ->action(function (array $data) use ($primary): void {
                $duplicate = Patient::query()->findOrFail($data['duplicate_patient_id']);
                app(PatientMergeService::class)->merge(
                    $primary,
                    $duplicate,
                    auth()->user(),
                    (bool) ($data['copy_missing_fields'] ?? true),
                );
                $primary->refresh();

                Notification::make()
                    ->success()
                    ->title('Patients merged successfully.')
                    ->body('All linked history now belongs to '.$primary->full_name.'.')
                    ->send();
            });
    }

    public static function patientLabel(Patient $patient): string
    {
        return implode(' · ', array_filter([
            $patient->full_name,
            $patient->formatted_patient_number,
            $patient->birth_date?->format('d.m.Y'),
            $patient->phone,
        ]));
    }
}
