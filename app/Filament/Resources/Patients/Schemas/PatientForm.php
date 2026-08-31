<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use App\Models\PatientGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema, bool $showPatientGroup = true): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('სახელი')
                    ->required()
                    ->validationMessages(['required' => 'სახელის მითითება აუცილებელია.'])
                    ->maxLength(100),

                TextInput::make('last_name')
                    ->label('გვარი')
                    ->required()
                    ->validationMessages(['required' => 'გვარის მითითება აუცილებელია.'])
                    ->maxLength(100),

                ...($showPatientGroup ? [
                    Select::make('patient_group_id')
                        ->label('პაციენტის ჯგუფი')
                        ->options(fn (?Patient $record): array => PatientGroup::query()
                            ->where(fn ($query) => $query
                                ->where('is_active', true)
                                ->when($record, fn ($query) => $query->orWhere('id', $record->patient_group_id)))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => PatientGroup::clinicId())
                        ->required()
                        ->live()
                        ->native(false)
                        ->preload(),
                ] : []),

                TextInput::make('phone')
                    ->label('ტელეფონი')
                    ->tel()
                    ->required(fn (Get $get): bool => PatientGroup::query()
                        ->whereKey($get('patient_group_id'))
                        ->where('slug', PatientGroup::CLINIC_SLUG)
                        ->exists())
                    ->validationMessages(['required' => 'ტელეფონის მითითება აუცილებელია.'])
                    ->live(onBlur: true)
                    ->helperText(function (?string $state, ?Patient $record): ?string {
                        if (blank($state)) {
                            return null;
                        }

                        $exists = Patient::query()->where('phone', trim($state))
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        return $exists ? 'ამ ტელეფონით სხვა პაციენტიც არსებობს.' : null;
                    })
                    ->maxLength(30),

                TextInput::make('personal_id')
                    ->label('პირადი ნომერი')
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.'])
                    ->maxLength(20),

                DatePicker::make('birth_date')
                    ->label('დაბადების თარიღი'),

                Textarea::make('notes')
                    ->label('შენიშვნა')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(['default' => 1, 'md' => 2]);
    }
}
