<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Doctor;
use App\Models\Patient;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::informationSection(),
            self::doctorsSection(),
            self::financialSection(),
        ]);
    }

    public static function informationSection(): Section
    {
        return Section::make('პაციენტის ინფორმაცია')
            ->schema([
                TextEntry::make('phone')->label('ტელეფონი')->placeholder('—'),
                TextEntry::make('personal_id')->label('პირადი ნომერი')->placeholder('—'),
                TextEntry::make('birth_date')->label('დაბადების თარიღი')->date('d.m.Y')->placeholder('—'),
                TextEntry::make('notes')->label('შენიშვნა')
                    ->visible(fn (Patient $record): bool => filled($record->notes))->columnSpanFull(),
            ])
            ->columns(['default' => 1, 'md' => 3])
            ->extraAttributes(['class' => 'renome-patient-information'])
            ->compact();
    }

    public static function doctorsSection(): Section
    {
        return Section::make('ექიმები')
            ->schema([
                TextEntry::make('assigned_doctors')->hiddenLabel()
                    ->state(fn (Patient $record): array => $record->doctors()
                        ->orderByDesc('patient_doctor.is_primary')->orderBy('first_name')->orderBy('last_name')
                        ->orderBy('patient_doctor.id')->get()
                        ->groupBy(fn (Doctor $doctor): int => $doctor->getKey())
                        ->map(function ($relations): string {
                            /** @var Doctor $doctor */
                            $doctor = $relations->first();
                            $roles = $relations->pluck('pivot.role')->filter()->unique()->values()->implode(', ');

                            return (filled($roles) ? $roles.' — ' : '').$doctor->full_name;
                        })->values()->all())
                    ->listWithLineBreaks()->bulleted()->placeholder('ექიმი ჯერ არ არის მიბმული.'),
                Actions::make([
                    Action::make('attachDoctor')->label('+ ექიმის დამატება')->link()
                        ->modalHeading('ექიმის დამატება')->modalSubmitActionLabel('დამატება')
                        ->schema([
                            Select::make('doctor_id')->label('ექიმი')
                                ->options(fn (Patient $record): array => Doctor::query()
                                    ->where('is_active', true)
                                    ->whereNotIn('id', $record->doctors()->select('doctors.id'))
                                    ->orderBy('first_name')->orderBy('last_name')->get()
                                    ->mapWithKeys(fn (Doctor $doctor): array => [
                                        $doctor->getKey() => $doctor->full_name
                                            .(filled($doctor->specialty) ? ' — '.$doctor->specialty : ''),
                                    ])->all())
                                ->searchable()->native(false)->required(),
                        ])
                        ->action(function (array $data, Patient $record): void {
                            $doctor = Doctor::query()->findOrFail($data['doctor_id']);
                            DB::table('patient_doctor')->insertOrIgnore([
                                'patient_id' => $record->getKey(), 'doctor_id' => $doctor->getKey(),
                                'is_primary' => false,
                                'role' => filled($doctor->specialty) ? trim($doctor->specialty) : 'ექიმი',
                                'assignment_source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $record->unsetRelation('doctors');
                            Notification::make()->success()->title('ექიმი პაციენტს დაემატა.')->send();
                        }),
                ]),
            ])->compact();
    }

    public static function financialSection(): Section
    {
        return Section::make('ფინანსური შეჯამება')
            ->schema([
                TextEntry::make('visits_total_price')->label('სულ დარიცხული')
                    ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'net_amount'))
                    ->listWithLineBreaks(),
                TextEntry::make('visits_paid_amount')->label('სულ გადახდილი')
                    ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'paid_amount'))
                    ->listWithLineBreaks(),
                TextEntry::make('visits_remaining_amount')->label('სულ გადასახდელი')
                    ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'remaining_amount'))
                    ->listWithLineBreaks(),
            ])->columns(3)->compact();
    }
}
