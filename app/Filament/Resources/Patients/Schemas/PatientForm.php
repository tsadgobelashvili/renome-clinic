<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Rules\UniquePatientIdentifier;
use App\Services\PatientDuplicateMatcher;
use App\Support\GeorgianNameTransliterator;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class PatientForm
{
    public static function configure(
        Schema $schema,
        bool $showPatientGroup = true,
        bool $includeInitialPartnerPayment = false,
    ): Schema {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('სახელი')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set, ?Patient $record) => self::syncLatinDraft('first_name', 'first_name_latin', $state, $get, $set, $record))
                    ->required()
                    ->validationMessages(['required' => 'სახელის მითითება აუცილებელია.'])
                    ->maxLength(100),

                TextInput::make('last_name')
                    ->label('გვარი')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set, ?Patient $record) => self::syncLatinDraft('last_name', 'last_name_latin', $state, $get, $set, $record))
                    ->required()
                    ->validationMessages(['required' => 'გვარის მითითება აუცილებელია.'])
                    ->maxLength(100),

                TextInput::make('first_name_latin')
                    ->live(onBlur: true)
                    ->label('სახელი (Latin)')
                    ->helperText('ქართული სახელიდან ავტომატურად ივსება; საჭიროების შემთხვევაში შეგიძლიათ შეასწოროთ.')
                    ->suffixAction(self::regenerateLatinAction('first_name', 'first_name_latin'))
                    ->visible(fn (): bool => self::canEditLatinNames())
                    ->maxLength(100),

                TextInput::make('last_name_latin')
                    ->live(onBlur: true)
                    ->label('გვარი (Latin)')
                    ->helperText('ხელით შესწორებული მნიშვნელობა ავტომატურად არ გადაიწერება.')
                    ->suffixAction(self::regenerateLatinAction('last_name', 'last_name_latin'))
                    ->visible(fn (): bool => self::canEditLatinNames())
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
                    ->rules([fn (?Patient $record) => new UniquePatientIdentifier($record?->getKey())])
                    ->validationMessages(['unique' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.'])
                    ->maxLength(20),

                DatePicker::make('birth_date')
                    ->live()
                    ->label('დაბადების თარიღი'),

                View::make('filament.resources.patients.duplicate-warning')
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Patient $record): bool => $record === null && self::possibleMatches($get)->isNotEmpty())
                    ->viewData(fn (Get $get): array => ['matches' => self::possibleMatches($get)]),

                Textarea::make('notes')
                    ->label('შენიშვნა')
                    ->rows(3)
                    ->columnSpanFull(),

                ...($includeInitialPartnerPayment ? [
                    Section::make('გადახდა')
                        ->visibleOn('create')
                        ->schema([
                            Repeater::make('initial_payments')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('amount')
                                        ->label('თანხა')
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->step(0.01)
                                        ->required(),
                                    Select::make('currency')
                                        ->label('ვალუტა')
                                        ->options([
                                            'USD' => '$ USD',
                                            'GEL' => '₾ GEL',
                                        ])
                                        ->default('USD')
                                        ->native(false)
                                        ->required(),
                                    Select::make('payment_method')
                                        ->label('მეთოდი')
                                        ->options(PaymentMethod::options())
                                        ->default(PaymentMethod::Cash->value)
                                        ->native(false)
                                        ->required(),
                                    DatePicker::make('paid_at')
                                        ->label('გადახდის თარიღი')
                                        ->default(today())
                                        ->displayFormat('d.m.Y')
                                        ->required(),
                                    TextInput::make('notes')
                                        ->label('შენიშვნა')
                                        ->maxLength(500),
                                ])
                                ->columns(['default' => 1, 'md' => 5])
                                ->defaultItems(0)
                                ->addActionLabel('+ გადახდის ნაწილი')
                                ->reorderable(false)
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),
                ] : []),
            ])
            ->columns(['default' => 1, 'md' => 2]);
    }

    private static function regenerateLatinAction(string $source, string $target): Action
    {
        return Action::make('regenerate_'.$target)
            ->label('ხელახლა გენერაცია')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(fn (Get $get, Set $set) => $set($target, GeorgianNameTransliterator::transliterate($get($source))))
            ->visible(fn (): bool => self::canEditLatinNames());
    }

    private static function syncLatinDraft(string $source, string $target, ?string $state, Get $get, Set $set, ?Patient $record): void
    {
        $current = trim((string) $get($target));
        $wasGenerated = ! $record || $current === '' || $current === GeorgianNameTransliterator::transliterate($record->{$source});
        if ($wasGenerated) {
            $set($target, GeorgianNameTransliterator::transliterate($state));
        }
    }

    private static function canEditLatinNames(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdministrator();
    }

    private static function possibleMatches(Get $get): Collection
    {
        return app(PatientDuplicateMatcher::class)->find([
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'first_name_latin' => $get('first_name_latin'),
            'last_name_latin' => $get('last_name_latin'),
            'birth_date' => $get('birth_date'),
            'phone' => $get('phone'),
        ]);
    }
}
