<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withClinicDebtBalances()
                ->with([
                    'patientGroup',
                    'doctors' => fn ($query) => $query
                        ->orderByDesc('patient_doctor.is_primary')
                        ->orderBy('first_name')
                        ->orderBy('last_name'),
                ]))
            ->columns([
                TextColumn::make('full_name')
                    ->label('პაციენტი')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchForClinic($search))
                    ->sortable(['last_name', 'first_name'])
                    ->weight('medium'),

                TextColumn::make('birth_date')
                    ->label('დაბადების თარიღი')
                    ->date('d.m.Y')
                    ->placeholder('—'),

                TextColumn::make('assigned_doctors')
                    ->label('ექიმი')
                    ->state(fn (Patient $record): array => $record->doctors
                        ->unique(fn (Doctor $doctor): int => $doctor->getKey())
                        ->map(fn (Doctor $doctor): string => $doctor->full_name)
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('მობილური')
                    ->placeholder('—'),

                TextColumn::make('patientGroup.name')
                    ->label('ჯგუფი')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('outstanding_balance')
                    ->label('სტატუსი')
                    ->state(function (Patient $record): array {
                        $balances = collect(array_keys(Currency::OPTIONS))
                            ->mapWithKeys(fn (string $currency): array => [
                                $currency => round((float) $record->getAttribute(
                                    'remaining_amount_'.strtolower($currency),
                                ), 2),
                            ])
                            ->filter(fn (float $amount): bool => $amount > 0.005)
                            ->map(fn (float $amount, string $currency): string => Currency::format($amount, $currency))
                            ->values()
                            ->all();

                        return $balances === [] ? ['Paid'] : $balances;
                    })
                    ->listWithLineBreaks()
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (string $state): string => $state === 'Paid' ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('patient_group_id')
                    ->label('პაციენტის ჯგუფი')
                    ->placeholder('ყველა ჯგუფი')
                    ->options(fn (): array => PatientGroup::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                SelectFilter::make('financial_status')
                    ->label('ფინანსური სტატუსი')
                    ->placeholder('ყველა')
                    ->options([
                        'debt' => 'აქვს დავალიანება',
                        'clear' => 'დავალიანება არ აქვს',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            ($data['value'] ?? null) === 'debt',
                            fn (Builder $query): Builder => $query->whereHasClinicDebt(),
                        )
                        ->when(
                            ($data['value'] ?? null) === 'clear',
                            fn (Builder $query): Builder => $query->whereHasClinicDebt(false),
                        )),

                SelectFilter::make('last_visit')
                    ->label('ბოლო ვიზიტი')
                    ->placeholder('ყველა')
                    ->options([
                        '7_days' => 'ბოლო 7 დღე',
                        '14_days' => 'ბოლო 14 დღე',
                        '1_month' => 'ბოლო 1 თვე',
                        '3_months' => 'ბოლო 3 თვე',
                        '1_year' => 'ბოლო 1 წელი',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = match ($data['value'] ?? null) {
                            '7_days' => today()->subDays(6),
                            '14_days' => today()->subDays(13),
                            '1_month' => today()->subMonth(),
                            '3_months' => today()->subMonths(3),
                            '1_year' => today()->subYear(),
                            default => null,
                        };

                        return $from
                            ? $query->whereLatestVisitBetween($from->toDateString(), today()->toDateString())
                            : $query;
                    }),

                SelectFilter::make('doctor_id')
                    ->label('ექიმი')
                    ->placeholder('ყველა ექიმი')
                    ->options(fn (): array => Doctor::query()
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                        ->get()
                        ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'visits',
                            fn (Builder $query): Builder => $query->where('doctor_id', $data['value']),
                        ),
                    )),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('ახალი პაციენტი')
                    ->icon('heroicon-o-plus')
                    ->color('primary'),
                Action::make('quickDebtFilter')
                    ->label('დავალიანება')
                    ->icon('heroicon-o-banknotes')
                    ->color(fn (ListPatients $livewire): string => data_get(
                        $livewire->tableFilters,
                        'financial_status.value',
                    ) === 'debt' ? 'danger' : 'gray')
                    ->action(fn (ListPatients $livewire) => $livewire->toggleDebtFilter()),
            ])
            ->recordActions([
                Action::make('createVisit')
                    ->label('ახალი ვიზიტი')
                    ->iconButton()
                    ->tooltip('ახალი ვიზიტი')
                    ->url(fn (Patient $record): string => VisitResource::getUrl('create', [
                        'patient_id' => $record->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Patient $record): string => PatientResource::getUrl('view', [
                'record' => $record,
            ]))
            ->defaultSort('created_at', 'desc');
    }
}
