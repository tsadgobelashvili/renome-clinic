<?php

namespace App\Filament\Resources\Visits\Schemas;

use App\Enums\PaymentMethod;
use App\Filament\Resources\TreatmentEstimates\Actions\CreateTreatmentEstimateAction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Product;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Services\NbgExchangeRate;
use App\Services\PaymentProcessor;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class VisitForm
{
    /** @param array<string, mixed> $data */
    public static function createInlinePatient(array $data): int
    {
        return Patient::create([
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'personal_id' => filled($data['personal_id'] ?? null) ? trim($data['personal_id']) : null,
            'birth_date' => $data['birth_date'] ?? null,
            'patient_group_id' => ($data['is_israel_patient'] ?? false)
                ? PatientGroup::israelPartnerId()
                : PatientGroup::clinicId(),
        ])->getKey();
    }

    /** @param array<int|string, array<string, mixed>> $items */
    public static function validatePatientTreatmentRequirement(mixed $patientId, array $items): void
    {
        if (blank($patientId)) {
            return;
        }

        $isPartner = Patient::query()->with('patientGroup')->find($patientId)?->isIsraelPartner() ?? false;
        $hasTreatment = collect($items)->contains(fn (array $item): bool => filled($item['treatment_case_id'] ?? null) || filled($item['custom_service_name'] ?? null));

        if ($isPartner && ! $hasTreatment) {
            throw ValidationException::withMessages([
                'treatmentCaseItems' => 'ისრაელის პაციენტის ვიზიტს მინიმუმ ერთი მანიპულაცია უნდა ჰქონდეს.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private static function normalizeTreatmentItemQuantity(array $data): array
    {
        if ((filled($data['treatment_case_id'] ?? null) || filled($data['custom_service_name'] ?? null))
            && blank($data['quantity'] ?? null)) {
            $data['quantity'] = 1;
        }

        return $data;
    }

    /** @param array{name: string, category: string, default_price?: mixed} $data */
    private static function createTreatmentCase(array $data): int
    {
        $name = trim($data['name']);
        $existing = TreatmentCase::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->first();

        if ($existing) {
            Notification::make()->warning()->title('ასეთი მანიპულაცია უკვე არსებობს.')
                ->body('არსებული ჩანაწერი ავტომატურად აირჩა.')
                ->send();

            return $existing->getKey();
        }

        return TreatmentCase::create([
            'name' => $name,
            'category' => $data['category'],
            'default_price' => filled($data['default_price'] ?? null)
                ? $data['default_price']
                : null,
            'is_active' => true,
        ])->getKey();
    }

    /** @return array<int|string, string> */
    public static function treatmentCaseSearchResults(string $search): array
    {
        return ['__manual__' => 'სხვა / ხელით ჩაწერა'] + TreatmentCase::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                self::patientSelect(),
                self::doctorSelect(),
                DatePicker::make('visit_date')->label('თარიღი')->default(now())->required(),
            ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),

            ToggleButtons::make('visit_type')
                ->label('ვიზიტის ტიპი')
                ->options(['consultation' => 'კონსულტაცია', 'treatment' => 'მკურნალობა'])
                ->default('treatment')->required()->inline()->live()->columnSpanFull(),

            Group::make([
                Hidden::make('consultation_source')->default('our_patient')->required(),
                TextInput::make('consultation_fee')
                    ->label('კონსულტაციის ფასი')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0)
                    ->suffixAction(self::currencyToggleAction('currency'))
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set, ?Visit $record): void {
                        $set('total_price', Visit::totalFromTreatmentItemState(
                            (array) ($get('treatmentCaseItems') ?? []),
                            $record?->total_price,
                            $state,
                            $get('currency') ?: Currency::DEFAULT,
                        ));
                    }),
            ])->columns(1)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('visit_type') === 'consultation'),

            Hidden::make('treatment_estimate_id'),
            Hidden::make('treatment_estimate_option_id'),

            Group::make([
                Actions::make([
                    self::tomographyAction(),
                    self::treatmentEstimateAction(),
                ]),
                Placeholder::make('tomography_summary')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => self::tomographySummary(
                        (array) ($get('treatmentCaseItems') ?? []),
                        $get('currency') ?: Currency::DEFAULT,
                    ))
                    ->visible(fn (Get $get): bool => self::hasTomographyItems(
                        (array) ($get('treatmentCaseItems') ?? []),
                    )),
            ])->columns(1)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('visit_type') === 'consultation'),

            Repeater::make('treatmentCaseItems')
                ->relationship()
                ->saveRelationshipsWhenHidden()
                ->default([['quantity' => 1]])
                ->label(fn (Get $get): string => $get('visit_type') === 'consultation' ? 'ტომოგრაფია' : 'შესრულებული სამუშაო')
                ->live()
                ->extraAttributes(['class' => 'renome-visit-work-items'])
                ->afterStateUpdated(fn (Get $get, Set $set, ?Visit $record): mixed => $set(
                    'total_price',
                    Visit::totalFromTreatmentItemState(
                        (array) ($get('treatmentCaseItems') ?? []),
                        $record?->total_price,
                        $get('visit_type') === 'consultation' ? $get('consultation_fee') : 0,
                        $get('currency') ?: Currency::DEFAULT,
                    ),
                ))
                ->schema([
                    Group::make([
                        Hidden::make('treatment_case_id')
                            ->label('მანიპულაცია')
                            ->required(fn (Get $get): bool => filled($get('service_choice')) && $get('service_choice') !== '__manual__')
                            ->rules([
                                fn (Get $get): mixed => $get('service_choice') === '__manual__'
                                    ? 'nullable'
                                    : Rule::exists('treatment_cases', 'id')->where('is_active', true),
                            ])
                            ->validationMessages([
                                'required' => 'არჩეული მანიპულაცია აღარ არის ხელმისაწვდომი. გთხოვთ ხელახლა აირჩიოთ.',
                                'exists' => 'არჩეული მანიპულაცია აღარ არის ხელმისაწვდომი. გთხოვთ ხელახლა აირჩიოთ.',
                            ]),
                        Select::make('service_choice')
                            ->label('მანიპულაცია')
                            ->options(['__manual__' => 'სხვა / ხელით ჩაწერა'])
                            ->getSearchResultsUsing(fn (string $search): array => self::treatmentCaseSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $value === '__manual__'
                                ? 'სხვა / ხელით ჩაწერა'
                                : TreatmentCase::query()->find($value)?->name)
                            ->in(function (Get $get): array {
                                $choice = $get('service_choice');

                                if ($choice === '__manual__') {
                                    return ['__manual__'];
                                }

                                return filled($choice) && TreatmentCase::query()
                                    ->whereKey($choice)
                                    ->where('is_active', true)
                                    ->exists()
                                        ? [(string) $choice]
                                        : [];
                            })
                            ->validationMessages([
                                'in' => 'არჩეული მანიპულაცია აღარ არის ხელმისაწვდომი. გთხოვთ ხელახლა აირჩიოთ.',
                            ])
                            ->searchable()
                            ->native(false)
                            ->dehydrated(false)
                            ->afterStateHydrated(function (mixed $state, Get $get, Set $set): void {
                                $set('service_choice', filled($get('treatment_case_id'))
                                    ? (string) $get('treatment_case_id')
                                    : (filled($get('custom_service_name')) ? '__manual__' : null));
                            })
                            ->createOptionForm([
                                TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                                Select::make('category')->label('კატეგორია')
                                    ->options(TreatmentCase::CATEGORIES)->native(false)->required(),
                                TextInput::make('default_price')->label('ფასი')->numeric()->minValue(0)
                                    ->step(0.01)->suffix('₾'),
                            ])
                            ->createOptionModalHeading('ახალი მანიპულაცია')
                            ->createOptionUsing(fn (array $data): int => self::createTreatmentCase($data))
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set, ?Visit $record): void {
                                if ($state === '__manual__') {
                                    $set('treatment_case_id', null);
                                    $set('unit_price', null);
                                    $set('../../total_price', Visit::totalFromTreatmentItemState(
                                        (array) ($get('../../treatmentCaseItems') ?? []),
                                        $record?->total_price,
                                        $get('../../visit_type') === 'consultation' ? $get('../../consultation_fee') : 0,
                                        $get('../../currency') ?: Currency::DEFAULT,
                                    ));

                                    return;
                                }

                                $set('treatment_case_id', filled($state) ? (int) $state : null);
                                $set('custom_service_name', null);

                                if (filled($state) && blank($get('quantity'))) {
                                    $set('quantity', 1);
                                }

                                $set('unit_price', TreatmentCase::query()->find($state)?->default_price);
                                $set('../../total_price', Visit::totalFromTreatmentItemState(
                                    (array) ($get('../../treatmentCaseItems') ?? []),
                                    $record?->total_price,
                                    $get('../../visit_type') === 'consultation' ? $get('../../consultation_fee') : 0,
                                    $get('../../currency') ?: Currency::DEFAULT,
                                ));
                            })
                            ->required(fn (Get $get): bool => $get('../../visit_type') === 'treatment'),
                        TextInput::make('custom_service_name')
                            ->label('მანიპულაციის დასახელება')
                            ->placeholder('ჩაწერეთ დასახელება')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => $get('service_choice') === '__manual__')
                            ->visible(fn (Get $get): bool => $get('service_choice') === '__manual__'),
                    ])->columns(1),
                    TextInput::make('teeth')->label('კბილი / კბილები')->maxLength(255)
                        ->placeholder('მაგ. 36 ან 11, 12, 13')
                        ->visible(fn (Get $get): bool => $get('../../visit_type') === 'treatment'),
                    TextInput::make('quantity')->label('რაოდენობა')->numeric()->integer()
                        ->minValue(1)->default(1)
                        ->afterStateHydrated(function (mixed $state, Set $set): void {
                            if (blank($state)) {
                                $set('quantity', 1);
                            }
                        })
                        ->required()->live(debounce: 300),
                    TextInput::make('unit_price')->label('ფასი')->numeric()->minValue(0)
                        ->step(0.01)
                        ->suffixAction(self::currencyToggleAction('currency'))
                        ->required()
                        ->validationMessages([
                            'required' => 'მიუთითეთ შესრულებული მანიპულაციის ფასი.',
                        ])
                        ->live(debounce: 300),
                    Hidden::make('currency')->default(fn (Get $get): string => $get('../../currency') ?: Currency::DEFAULT)->live(),
                    TextInput::make('exchange_rate')->label('კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                        ->required(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                        ->visible(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                        ->live(debounce: 300),
                    Placeholder::make('manipulation_total_preview')->label('ჯამი')
                        ->extraAttributes(['class' => 'renome-visit-work-item-total'])
                        ->content(fn (Get $get): string => self::money(
                            (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Hidden::make('comment'),
                    Group::make([
                        Placeholder::make('expense_summary')->hiddenLabel()
                            ->content(function (Get $get): string {
                                $currency = $get('../../currency') ?: Currency::DEFAULT;
                                $expenses = self::directExpensesTotal($get('directExpenses') ?? [], $currency);

                                if ($expenses <= 0) {
                                    return '';
                                }

                                $total = (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0);

                                return 'ხარჯი '.self::money($expenses, $currency).' · ხარჯის შემდეგ '.self::money($total - $expenses, $currency);
                            }),
                        Repeater::make('directExpenses')->relationship()->hiddenLabel()->live()
                            ->extraAttributes(['class' => 'renome-visit-expenses'])
                            ->schema([
                                TextInput::make('name')->label('ხარჯი')->placeholder('ხარჯის დასახელება')
                                    ->required()->maxLength(255),
                                TextInput::make('quantity')->label('რაოდ.')->numeric()->integer()->minValue(1)->default(1)
                                    ->afterStateHydrated(function (mixed $state, Set $set): void {
                                        if (blank($state)) {
                                            $set('quantity', 1);
                                        }
                                    })->required(),
                                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)
                                    ->step(0.01)->suffixAction(self::currencyToggleAction('currency'))
                                    ->required()->live(debounce: 300),
                                Hidden::make('currency')->default(Currency::DEFAULT),
                            ])
                            ->table([
                                TableColumn::make('ხარჯი')->width('52%'),
                                TableColumn::make('რაოდ.')->width('18%'),
                                TableColumn::make('თანხა')->width('28%'),
                                TableColumn::make('')->width('2%'),
                            ])
                            ->addAction(fn (Action $action): Action => $action->label('+ ხარჯის დამატება')->link())
                            ->deleteAction(fn (Action $action): Action => $action->tooltip('ხარჯის წაშლა'))
                            ->defaultItems(0)->reorderable(false)->compact(),
                    ])->visible(fn (Get $get): bool => $get('../../visit_type') === 'treatment'),
                ])
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeTreatmentItemQuantity($data))
                ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeTreatmentItemQuantity($data))
                ->table(fn (Get $get): array => $get('visit_type') === 'consultation'
                    ? [
                        TableColumn::make('სერვისი')->width('42%'),
                        TableColumn::make('რაოდენობა')->width('16%'),
                        TableColumn::make('ფასი')->width('20%'),
                        TableColumn::make('ჯამი')->width('17%'),
                    ]
                    : [
                        TableColumn::make('მანიპულაცია')->width('25%'),
                        TableColumn::make('კბილი / კბილები')->width('16%'),
                        TableColumn::make('რაოდ.')->width('9%'),
                        TableColumn::make('ფასი')->width('12%'),
                        TableColumn::make('კურსი')->width('9%'),
                        TableColumn::make('ჯამი')->width('11%'),
                        TableColumn::make('ხარჯი')->width('18%'),
                    ])
                ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $fingerprints = collect($value ?? [])->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null)
                        || filled($item['custom_service_name'] ?? null))
                        ->map(fn (array $item): string => VisitTreatmentCase::makeFingerprint(
                            filled($item['treatment_case_id'] ?? null) ? (int) $item['treatment_case_id'] : null,
                            (int) ($item['quantity'] ?? 1),
                            filled($item['teeth'] ?? null) ? trim((string) $item['teeth']) : null,
                            filled($item['comment'] ?? null) ? trim((string) $item['comment']) : null,
                            filled($item['custom_service_name'] ?? null) ? trim((string) $item['custom_service_name']) : null,
                        ));

                    if ($fingerprints->duplicates()->isNotEmpty()) {
                        $fail('ზუსტად ერთნაირი შესრულებული სამუშაო ორჯერ ვერ დაემატება.');
                    }

                    foreach ($value ?? [] as $item) {
                        $manipulationTotal = (int) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
                        $expensesTotal = self::directExpensesTotal(
                            $item['directExpenses'] ?? [],
                            $get('currency') ?: Currency::DEFAULT,
                        );

                        if ($expensesTotal > $manipulationTotal) {
                            $fail('პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.');

                            break;
                        }
                    }
                }])
                ->addAction(fn (Action $action): Action => $action->label('შესრულებული სამუშაოს დამატება')
                    ->icon(Heroicon::Plus)->iconButton()->tooltip('შესრულებული სამუშაოს დამატება'))
                ->deleteAction(fn (Action $action): Action => $action->tooltip('წაშლა'))
                ->reorderable(false)->compact()->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('visit_type') === 'treatment'),

            Group::make([
                Hidden::make('total_price'),
                Placeholder::make('visit_total_preview')->label('სრული ღირებულება')
                    ->content(fn (Get $get): string => self::treatmentTotalsBreakdown(
                        (array) ($get('treatmentCaseItems') ?? []),
                        $get('currency') ?: Currency::DEFAULT,
                        (float) ($get('consultation_fee') ?? 0),
                    ))->columnSpan(['default' => 1, 'lg' => 2]),
                Hidden::make('currency')->default(Currency::DEFAULT)->live(),
                Group::make([
                    TextInput::make('discount_value')->label('ფასდაკლება')->numeric()->minValue(0)
                        ->step(0.01)->default(0)->required()->live(onBlur: true)
                        ->columnSpan(['default' => 1, 'sm' => 3])
                        ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if ($get('discount_type') === 'percent' && (float) $value > 100) {
                                $fail('ფასდაკლება 100%-ზე მეტი ვერ იქნება.');
                            }
                            if (filled($get('total_price')) && $get('discount_type') === 'amount' && (float) $value > (float) $get('total_price')) {
                                $fail('ფასდაკლება ღირებულებას ვერ გადააჭარბებს.');
                            }
                        }])
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if ((float) $state !== 100.0) {
                                $set('discount_reason', null);
                                $set('discount_comment', null);
                            }
                        }),
                    Select::make('discount_type')->label('ერთეული')->options(['amount' => '₾', 'percent' => '%'])
                        ->default('amount')->required()->native(false)->live()
                        ->columnSpan(['default' => 1, 'sm' => 2]),
                    Select::make('discount_reason')->label('ფასდაკლების მიზეზი')->options(Visit::DISCOUNT_REASONS)
                        ->native(false)->required(fn (Get $get): bool => $get('discount_type') === 'percent' && (float) $get('discount_value') === 100.0)
                        ->visible(fn (Get $get): bool => $get('discount_type') === 'percent' && (float) $get('discount_value') === 100.0)
                        ->live()->columnSpanFull(),
                    TextInput::make('discount_comment')->label('მიზეზის აღწერა')->maxLength(255)
                        ->required(fn (Get $get): bool => $get('discount_reason') === 'other')
                        ->visible(fn (Get $get): bool => $get('discount_type') === 'percent'
                            && (float) $get('discount_value') === 100.0
                            && $get('discount_reason') === 'other')
                        ->columnSpanFull(),
                ])->columns(['default' => 1, 'sm' => 5])
                    ->extraAttributes(['class' => 'gap-x-4'])
                    ->columnSpan(['default' => 1, 'lg' => 4])
                    ->visible(fn (Get $get): bool => $get('visit_type') === 'treatment'),
                Group::make([
                    Placeholder::make('staged_paid_preview')->label('გადახდილი')
                        ->content(fn ($livewire, Get $get): string => self::money(
                            method_exists($livewire, 'getStagedPaidAmount') ? $livewire->getStagedPaidAmount() : 0,
                            $get('currency') ?: Currency::DEFAULT,
                        ))
                        ->extraAttributes(['class' => 'text-success-600 dark:text-success-400'])
                        ->columnSpan(['default' => 1, 'sm' => 2]),
                    Placeholder::make('remaining_amount_preview')->label('დარჩენილი')
                        ->content(fn ($livewire, Get $get): string => self::money(
                            $livewire->getCurrentRemainingAmount(),
                            $get('currency') ?: Currency::DEFAULT,
                        ))
                        ->extraAttributes(fn ($livewire): array => [
                            'class' => ($livewire->getCurrentRemainingAmount() ?? 0) > 0
                                ? 'text-danger-600 dark:text-danger-400'
                                : 'text-success-600 dark:text-success-400',
                        ])
                        ->columnSpan(['default' => 1, 'sm' => 2]),
                    Actions::make([self::paymentAction()])->columnSpan(['default' => 1, 'sm' => 1]),
                    Placeholder::make('staged_payment_summary')->label('გადახდის მეთოდი')
                        ->content(fn ($livewire): string => method_exists($livewire, 'getStagedPaymentSummary')
                            ? $livewire->getStagedPaymentSummary()
                            : '—')
                        ->visible(fn ($livewire): bool => method_exists($livewire, 'hasPendingPayment') && $livewire->hasPendingPayment())
                        ->columnSpanFull(),
                    Placeholder::make('staged_payment_error')->label('შესასწორებელია')
                        ->content('შეტანილი გადახდა საბოლოო გადასახდელ თანხას აღემატება. გახსენით გადახდა და შეასწორეთ თანხა.')
                        ->visible(fn ($livewire): bool => method_exists($livewire, 'stagedPaymentExceedsPayable') && $livewire->stagedPaymentExceedsPayable())
                        ->extraAttributes(['class' => 'text-danger-600 dark:text-danger-400'])
                        ->columnSpanFull(),
                ])->columns(['default' => 1, 'sm' => 5])
                    ->extraAttributes(['class' => 'gap-x-4'])
                    ->columnSpan(['default' => 1, 'lg' => 6]),
            ])->columns(['default' => 1, 'lg' => 12])->columnSpanFull(),

            Textarea::make('comment')->label('კომენტარი')->rows(2)->columnSpanFull(),

            Section::make('გადახდების ისტორია')->schema([
                TextEntry::make('payments_history')->hiddenLabel()
                    ->state(fn (?Visit $record): array => $record?->payments()->with('splits')->latest('payment_date')->latest('id')->get()
                        ->map(fn ($payment): string => $payment->payment_date->format('d.m.Y').' — '
                            .Currency::format($payment->amount, $payment->currency).' — '.$payment->method_display)->all() ?? [])
                    ->listWithLineBreaks()->bulleted()->placeholder('გადახდები არ არის'),
            ])->visible(fn (?Visit $record): bool => $record?->exists === true)
                ->collapsible()->collapsed()->columnSpanFull(),
        ]);
    }

    private static function patientSelect(): Select
    {
        return Select::make('patient_id')->label('პაციენტი')->relationship('patient', 'first_name')
            ->getOptionLabelFromRecordUsing(fn (Patient $record): string => $record->formatted_patient_number.' — '.$record->full_name)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Patient::query()
                ->searchForClinic($search)
                ->orderBy('patient_number')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Patient $patient): array => [
                    $patient->getKey() => $patient->formatted_patient_number.' — '.$patient->full_name,
                ])
                ->all())
            ->preload(false)
            ->createOptionForm([
                TextInput::make('first_name')->label('სახელი')->required()->maxLength(100),
                TextInput::make('last_name')->label('გვარი')->required()->maxLength(100),
                Toggle::make('is_israel_patient')->label('ისრაელის პაციენტი')->default(false)->live(),
                TextInput::make('phone')->label('მობილური')->tel()
                    ->required(fn (Get $get): bool => ! (bool) $get('is_israel_patient'))->maxLength(30),
                TextInput::make('personal_id')->label('პირადი ნომერი')->maxLength(20)
                    ->unique(table: Patient::class, column: 'personal_id')
                    ->validationMessages([
                        'unique' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.',
                    ]),
            ])->createOptionModalHeading('ახალი პაციენტის შექმნა')
            ->createOptionUsing(fn (array $data): int => self::createInlinePatient($data))
            ->live()->afterStateUpdated(function (Set $set): void {
                $set('treatment_estimate_id', null);
                $set('treatment_estimate_option_id', null);
            })->required();
    }

    private static function doctorSelect(): Select
    {
        return Select::make('doctor_id')->label('ექიმი')->relationship(
            name: 'doctor', titleAttribute: 'first_name',
            modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
        )->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)->searchable()
            ->getSearchResultsUsing(function (string $search, Get $get): array {
                $patientId = $get('patient_id');

                return Doctor::query()
                    ->where('is_active', true)
                    ->searchByName($search)
                    ->when(filled($patientId), fn (Builder $query): Builder => $query->orderByRaw(
                        'CASE WHEN EXISTS (SELECT 1 FROM patient_doctor WHERE patient_doctor.doctor_id = doctors.id AND patient_doctor.patient_id = ?) THEN 0 ELSE 1 END',
                        [$patientId],
                    ))
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])
                    ->all();
            })
            ->preload(false);
    }

    public static function tomographyAction(bool $standalone = false): Action
    {
        return Action::make('manageTomography')
            ->label(fn (Get $get): string => self::hasTomographyItems(
                (array) ($get('treatmentCaseItems') ?? []),
            ) ? 'ტომოგრაფიის რედაქტირება' : '+ 3D CT')
            ->button()
            ->modalHeading('ტომოგრაფია')
            ->modalWidth($standalone ? '6xl' : 'lg')
            ->modalSubmitActionLabel('დადასტურება')
            ->databaseTransaction()
            ->fillForm(function ($livewire) use ($standalone): array {
                if ($standalone) {
                    return [
                        'consultation_source' => 'our_patient',
                        'tomographyItems' => [['quantity' => 1]],
                        'amount' => 0,
                        'currency' => Currency::DEFAULT,
                        'paymentSplits' => [],
                    ];
                }

                $state = $livewire->form->getRawState();
                $items = collect($state['treatmentCaseItems'] ?? [])
                    ->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null))
                    ->all();
                $due = self::tomographyAmountDue($livewire, $items);

                return [
                    'consultation_source' => $state['consultation_source'] ?? 'our_patient',
                    'tomographyItems' => $items ?: [['quantity' => 1]],
                    'amount' => $due,
                    'currency' => $state['currency'] ?? Currency::DEFAULT,
                    'paymentSplits' => $due > 0
                        ? [[
                            'payment_method' => 'cash',
                            'amount' => $due,
                            'currency' => $state['currency'] ?? Currency::DEFAULT,
                            'exchange_rate' => null,
                        ]]
                        : [],
                ];
            })
            ->schema([
                Grid::make($standalone ? 12 : 1)
                    ->extraAttributes(['class' => $standalone ? 'renome-tomography-form' : ''])
                    ->schema([
                    ...($standalone ? [
                        Select::make('patient_id')
                            ->key('dashboard-tomography-patient')
                            ->label('პაციენტი')
                            ->getSearchResultsUsing(fn (string $search): array => Patient::query()
                                ->searchForClinic($search)
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Patient $patient): array => [$patient->getKey() => $patient->full_name])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Patient::query()->find($value)?->full_name)
                            ->searchable()
                            ->createOptionForm([
                                TextInput::make('first_name')->label('სახელი')->required()->maxLength(100),
                                TextInput::make('last_name')->label('გვარი')->required()->maxLength(100),
                                TextInput::make('phone')->label('მობილური')->tel()->maxLength(30),
                                DatePicker::make('birth_date')->label('დაბადების თარიღი')->displayFormat('d.m.Y'),
                                TextInput::make('personal_id')->label('პირადი ნომერი')->maxLength(20)
                                    ->unique(table: Patient::class, column: 'personal_id'),
                            ])
                            ->createOptionModalHeading('პაციენტის დამატება')
                            ->createOptionAction(fn (Action $action): Action => $action->label('+ პაციენტის დამატება'))
                            ->createOptionUsing(fn (array $data): int => self::createInlinePatient($data))
                            ->required()
                            ->columnSpan($standalone ? 5 : 1),
                        Select::make('doctor_id')
                            ->label('ექიმი')
                            ->options(fn (): array => Doctor::query()->where('is_active', true)
                                ->orderBy('first_name')->orderBy('last_name')->get()
                                ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])->all())
                            ->searchable()
                            ->native(false)
                            ->columnSpan($standalone ? 4 : 1),
                    ] : []),
                    Hidden::make('amount')->live(),
                    Hidden::make('currency')->default(Currency::DEFAULT)->live(),
                    Select::make('consultation_source')
                        ->label('წყარო')
                        ->options(Visit::CONSULTATION_SOURCES)
                        ->default('our_patient')
                        ->native(false)
                        ->required()
                        ->extraAttributes(['class' => $standalone ? 'renome-tomography-source' : ''])
                        ->columnSpan($standalone ? 3 : 1),
                    Repeater::make('tomographyItems')
                        ->hiddenLabel()
                        ->extraAttributes(['class' => $standalone ? 'renome-tomography-items' : ''])
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set, $livewire) use ($standalone): void {
                            self::syncTomographyPaymentState($state, $get, $set, $livewire, $standalone);
                        })
                        ->schema([
                            Select::make('treatment_case_id')
                                ->label('სერვისი')
                                ->options(fn (): array => TreatmentCase::query()
                                    ->where('is_active', true)
                                    ->where('category', 'tomography')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->native(false)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set, $livewire) use ($standalone): void {
                                    if (blank($get('quantity'))) {
                                        $set('quantity', 1);
                                    }

                                    $set('unit_price', TreatmentCase::query()
                                        ->where('category', 'tomography')
                                        ->find($state)?->default_price);

                                    self::syncTomographyPaymentState(
                                        $get('../../tomographyItems'),
                                        $get,
                                        $set,
                                        $livewire,
                                        $standalone,
                                        '../../',
                                    );
                                }),
                            TextInput::make('quantity')
                                ->label('რაოდენობა')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(debounce: 300),
                            TextInput::make('unit_price')
                                ->label('ფასი')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->suffix(fn (Get $get): string => Currency::symbol(
                                    $get('../../currency') ?: Currency::DEFAULT,
                                ))
                                ->required()
                                ->live(debounce: 300),
                            Placeholder::make('tomography_line_total')
                                ->label('ჯამი')
                                ->content(fn (Get $get): string => self::money(
                                    (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                                    $get('../../currency') ?: Currency::DEFAULT,
                                )),
                        ])
                        ->table([
                            TableColumn::make('სერვისი')->width('42%'),
                            TableColumn::make('რაოდენობა')->width('16%'),
                            TableColumn::make('ფასი')->width('20%'),
                            TableColumn::make('ჯამი')->width('17%'),
                        ])
                        ->defaultItems(1)
                        ->minItems(0)
                        ->reorderable(false)
                        ->compact()
                        ->addAction(fn (Action $action): Action => $action
                            ->label('+ სერვისი')
                            ->link())
                        ->deleteAction(fn (Action $action): Action => $action
                            ->iconButton()
                            ->color('danger')
                            ->tooltip('წაშლა'))
                        ->columnSpan($standalone ? 12 : 1),
                    Repeater::make('paymentSplits')
                        ->label('გადახდა')
                        ->extraAttributes(['class' => $standalone ? 'renome-tomography-payments' : ''])
                        ->live()
                        ->schema([
                            Select::make('payment_method')
                                ->label('მეთოდი')
                                ->options(PaymentMethod::options())
                                ->native(false)
                                ->required()
                                ->distinct(),
                            TextInput::make('amount')
                                ->label('თანხა')
                                ->numeric()
                                ->minValue(0.01)
                                ->step(0.01)
                                ->suffixAction(self::paymentCurrencyToggleAction('paymentSplits'))
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (Set $set): mixed => $set('amount_manually_overridden', true)),
                            Hidden::make('currency')->default(fn (Get $get): string => $get('../../currency') ?: Currency::DEFAULT)->live(),
                            Hidden::make('amount_manually_overridden')->default(false),
                            TextInput::make('exchange_rate')->label('კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                                ->required(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                                ->visible(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                                ->live()
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    if (($get('currency') ?: Currency::DEFAULT) === ($get('../../currency') ?: Currency::DEFAULT)
                                        || ($get('amount_manually_overridden') ?? false)
                                        || (float) $state <= 0) {
                                        return;
                                    }

                                    $set('amount', Money::decimal(self::remainingBeforePaymentRow($get, 'paymentSplits') / (float) $state));
                                }),
                        ])
                        ->table([
                            TableColumn::make('მეთოდი')->width('36%'),
                            TableColumn::make('თანხა')->width('34%'),
                            TableColumn::make('კურსი')->width('28%'),
                        ])
                        ->defaultItems(0)
                        ->minItems(0)
                        ->maxItems(2)
                        ->reorderable(false)
                        ->compact()
                        ->addAction(fn (Action $action): Action => $action
                            ->label('+ გადახდის მეთოდი')
                            ->link())
                        ->rules([fn (Get $get, $livewire) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $livewire, $standalone): void {
                            if (blank($value)) {
                                return;
                            }

                            $due = self::tomographyAmountDue(
                                $livewire,
                                (array) ($get('tomographyItems') ?? []),
                                currency: $standalone ? ($get('currency') ?: Currency::DEFAULT) : null,
                            );
                            $currency = $get('currency') ?: Currency::DEFAULT;
                            $processor = app(PaymentProcessor::class);
                            $splitTotal = $processor->distributedMinorUnits((array) $value, $currency);
                            $tolerance = $processor->distributionToleranceMinorUnits((array) $value, $currency);

                            if (abs($splitTotal - Money::minorUnits($due)) > $tolerance) {
                                $fail('გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადასახდელ თანხას.');
                            }
                        }])
                        ->columnSpan($standalone ? 12 : 1),
                    Group::make([
                        Placeholder::make('tomography_due_preview')
                            ->label('სულ')
                            ->extraAttributes(['class' => $standalone ? 'renome-tomography-summary-total' : ''])
                            ->content(fn (Get $get, $livewire): string => self::money(
                                self::tomographyAmountDue(
                                    $livewire,
                                    (array) ($get('tomographyItems') ?? []),
                                    currency: $standalone ? ($get('currency') ?: Currency::DEFAULT) : null,
                                ),
                                $get('currency') ?: Currency::DEFAULT,
                            )),
                        Placeholder::make('tomography_distributed_preview')
                            ->label('გადახდილი')
                            ->extraAttributes(['class' => $standalone ? 'renome-tomography-summary-paid' : ''])
                            ->content(fn (Get $get, $livewire): string => self::money(
                                app(PaymentProcessor::class)->reconciledDistributedAmount(
                                    self::tomographyAmountDue(
                                        $livewire,
                                        (array) ($get('tomographyItems') ?? []),
                                        currency: $standalone ? ($get('currency') ?: Currency::DEFAULT) : null,
                                    ),
                                    (array) ($get('paymentSplits') ?? []),
                                    $get('currency') ?: Currency::DEFAULT,
                                ),
                                $get('currency') ?: Currency::DEFAULT,
                            )),
                        Placeholder::make('tomography_remaining_preview')
                            ->label('დარჩენილი')
                            ->content(fn (Get $get, $livewire): string => self::money(
                                app(PaymentProcessor::class)->remaining(self::tomographyAmountDue(
                                    $livewire,
                                    (array) ($get('tomographyItems') ?? []),
                                    currency: $standalone ? ($get('currency') ?: Currency::DEFAULT) : null,
                                ), (array) ($get('paymentSplits') ?? []), $get('currency') ?: Currency::DEFAULT),
                                $get('currency') ?: Currency::DEFAULT,
                            )),
                    ])
                        ->columns(3)
                        ->extraAttributes(['class' => $standalone ? 'renome-tomography-payment-summary' : ''])
                        ->columnSpan($standalone ? 12 : 1),
                ]),
            ])
            ->action(function (array $data, $livewire, Action $action) use ($standalone): void {
                if ((! $standalone) && (! self::hasValidPaymentContext($livewire))) {
                    $action->halt(shouldRollBackDatabaseTransaction: true);
                }

                $items = collect($data['tomographyItems'] ?? [])
                    ->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null))
                    ->map(fn (array $item): array => self::normalizeTreatmentItemQuantity($item))
                    ->all();
                if ($standalone) {
                    self::createStandaloneTomography($data, $items);
                    Notification::make()->success()->title('ტომოგრაფია და გადახდა შენახულია.')->send();
                    $livewire->resetTable();
                    $livewire->dispatch('$refresh');

                    return;
                }

                $state = $livewire->form->getRawState();
                $state['consultation_source'] = $data['consultation_source'] ?? 'our_patient';
                $state['treatmentCaseItems'] = $items;
                $state['total_price'] = Visit::totalFromTreatmentItemState(
                    $items,
                    null,
                    $state['consultation_fee'] ?? 0,
                    $state['currency'] ?? Currency::DEFAULT,
                );
                $due = self::tomographyAmountDue($livewire, $items, $state['total_price']);
                $splits = array_values($data['paymentSplits'] ?? []);

                if ($splits !== []) {
                    $splits = app(PaymentProcessor::class)->prepare(
                        $due,
                        $splits,
                        $state['currency'] ?? Currency::DEFAULT,
                    )['rows'];
                }

                // Assign the existing form state directly. Calling form->fill() here would
                // reload relationship records on Edit and undo a removal made in the modal.
                $livewire->data = $state;

                if ($splits !== []) {
                    $paymentData = [
                        'amount' => $due,
                        'currency' => $state['currency'] ?? Currency::DEFAULT,
                        'splits' => $splits,
                    ];

                    if (! $livewire->getRecord()?->exists && method_exists($livewire, 'submitPaymentAndCreate')) {
                        $livewire->submitPaymentAndCreate($paymentData);

                        return;
                    }

                    $livewire->submitPayment($paymentData);

                    return;
                }

                if ($livewire->getRecord()?->exists) {
                    $livewire->save(shouldRedirect: false);

                    return;
                }

                $livewire->create();
            });
    }

    private static function treatmentEstimateAction(): Action
    {
        return CreateTreatmentEstimateAction::make(
            patientId: fn (Get $get): mixed => $get('patient_id'),
            doctorId: fn (Get $get): mixed => $get('doctor_id'),
        )
            ->button()
            ->disabled(fn (Get $get): bool => blank($get('patient_id')));
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function tomographyAmountDue($livewire, array $items, ?float $total = null, ?string $currency = null): float
    {
        $state = $currency === null ? $livewire->form->getRawState() : [];
        $currency ??= $state['currency'] ?? Currency::DEFAULT;
        $total ??= Visit::totalFromTreatmentItemState(
            $items,
            null,
            $state['consultation_fee'] ?? 0,
            $currency,
        );
        $paid = $state === [] ? 0 : ($livewire->getRecord()?->payments()
            ->where('currency', $currency)
            ->sum('amount') ?? 0);

        return app(PaymentProcessor::class)->amountDue($total, $paid);
    }

    private static function syncTomographyPaymentState(
        mixed $items,
        Get $get,
        Set $set,
        $livewire,
        bool $standalone,
        string $root = '',
    ): void {
        $splits = collect($get($root.'paymentSplits') ?? []);

        if ($splits->count() > 1) {
            return;
        }

        $debtCurrency = $get($root.'currency') ?: Currency::DEFAULT;
        $due = self::tomographyAmountDue(
            $livewire,
            (array) ($items ?? []),
            currency: $standalone ? $debtCurrency : null,
        );
        $set($root.'amount', $due);

        if ($due <= 0) {
            $set($root.'paymentSplits', []);

            return;
        }

        $split = $splits->first() ?? ['payment_method' => 'cash'];
        $splitCurrency = $split['currency'] ?? $debtCurrency;
        $rate = (float) ($split['exchange_rate'] ?? 0);
        $split['currency'] = $splitCurrency;
        $split['amount'] = $splitCurrency === $debtCurrency
            ? Money::decimal($due)
            : ($rate > 0 ? Money::decimal($due / $rate) : $split['amount'] ?? null);
        $set($root.'paymentSplits', [$split]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function createStandaloneTomography(array $data, array $items): void
    {
        DB::transaction(function () use ($data, $items): void {
            $currency = $data['currency'] ?? Currency::DEFAULT;
            $total = Visit::totalFromTreatmentItemState($items, null, 0, $currency);
            $visit = Visit::query()->create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'] ?? null,
                'visit_date' => today(),
                'visit_type' => 'consultation',
                'consultation_source' => $data['consultation_source'] ?? 'our_patient',
                'consultation_fee' => 0,
                'currency' => $currency,
                'total_price' => $total,
            ]);

            foreach ($items as $item) {
                $visit->treatmentCaseItems()->create($item);
            }

            $splits = array_values($data['paymentSplits'] ?? []);
            if ($splits !== []) {
                app(PaymentProcessor::class)->process([
                    'visit_id' => $visit->getKey(),
                    'amount' => $total,
                    'currency' => $currency,
                    'payment_date' => today()->toDateString(),
                ], $splits);
            }
        });
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function hasTomographyItems(array $items): bool
    {
        return collect($items)->contains(
            fn (array $item): bool => filled($item['treatment_case_id'] ?? null),
        );
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function tomographySummary(array $items, string $currency): string
    {
        $items = collect($items)
            ->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null));
        $names = TreatmentCase::query()
            ->whereKey($items->pluck('treatment_case_id')->all())
            ->pluck('name', 'id');

        return $items->map(function (array $item) use ($currency, $names): string {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $total = $quantity * (float) ($item['unit_price'] ?? 0);

            return ($names[$item['treatment_case_id']] ?? 'ტომოგრაფია')
                .' × '.$quantity.' — '.self::money($total, $currency);
        })->implode(' · ');
    }

    private static function paymentAction(): Action
    {
        return Action::make('makePayment')
            ->label(fn ($livewire): string => method_exists($livewire, 'hasPendingPayment') && $livewire->hasPendingPayment()
                ? 'რედაქტირება'
                : 'გადახდა')
            ->modalHeading('გადახდა')->modalWidth('md')
            ->modalSubmitActionLabel('დადასტურება')
            ->databaseTransaction()
            ->fillForm(function ($livewire): array {
                if (method_exists($livewire, 'getPendingPaymentFormData')
                    && ($pendingPayment = $livewire->getPendingPaymentFormData()) !== null) {
                    return $pendingPayment;
                }

                $amount = $livewire->getCurrentRemainingAmount();
                $currency = $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT;

                return [
                    'amount' => $amount,
                    'service_amount' => $amount,
                    'currency' => $currency,
                    'products' => [],
                    'splits' => Money::minorUnits($amount) > 0
                        ? [['payment_method' => 'cash', 'amount' => $amount, 'currency' => $currency, 'exchange_rate' => null]]
                        : [],
                ];
            })
            ->schema([
                Hidden::make('service_amount'),
                Group::make([
                    TextInput::make('amount')->label('სრული გადასახდელი')->numeric()->minValue(0)
                        ->step(0.01)->suffix(fn (Get $get): string => Currency::symbol($get('currency') ?: Currency::DEFAULT))
                        ->required()->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $splits = array_values((array) ($get('splits') ?? []));

                            if (count($splits) === 1 && ($splits[0]['currency'] ?? $get('currency')) === $get('currency')) {
                                $splits[0]['amount'] = Money::decimal($state);
                                $set('splits', $splits);

                                return;
                            }

                            $autoForeignKeys = collect($splits)->keys()->filter(fn (int $key): bool => ($splits[$key]['currency'] ?? $get('currency')) !== $get('currency')
                                && ! ($splits[$key]['amount_manually_overridden'] ?? false)
                                && (float) ($splits[$key]['exchange_rate'] ?? 0) > 0
                            )->values();

                            if ($autoForeignKeys->count() !== 1) {
                                return;
                            }

                            $key = $autoForeignKeys->sole();
                            $otherRows = $splits;
                            unset($otherRows[$key]);
                            $remaining = app(PaymentProcessor::class)->remaining($state, $otherRows, $get('currency') ?: Currency::DEFAULT);
                            $splits[$key]['amount'] = Money::decimal($remaining / (float) $splits[$key]['exchange_rate']);
                            $set('splits', $splits);
                        })
                        ->columnSpanFull(),
                    Hidden::make('currency')->default(Currency::DEFAULT)->live(),
                ])->columns(4),
                Group::make([
                    Placeholder::make('visit_amount_preview')->label('ვიზიტის ჯამი')
                        ->content(fn (Get $get): string => self::money(
                            Money::decimal($get('service_amount')),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('products_amount_preview')->label('პროდუქტების ჯამი')
                        ->content(fn (Get $get): string => self::money(
                            self::paymentProductTotal((array) ($get('products') ?? [])),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('grand_amount_preview')->label('სრული გადასახდელი')
                        ->content(fn (Get $get): string => self::money(
                            Money::decimal($get('service_amount')) + self::paymentProductTotal((array) ($get('products') ?? [])),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                ])->columns(3),
                Repeater::make('products')->label('პროდუქტები')->schema([
                    Select::make('product_id')->label('პროდუქტი')
                        ->options(fn (): array => Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required()->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $set('unit_price', Product::query()->find($state)?->selling_price);
                            self::syncPaymentProductTotalFromRow($get, $set);
                        }),
                    TextInput::make('quantity')->label('რაოდ.')->numeric()->integer()->minValue(1)->default(1)->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentProductTotalFromRow($get, $set)),
                    Hidden::make('unit_price'),
                    Placeholder::make('product_line_total')->label('ჯამი')->content(fn (Get $get): string => self::money(
                        max(1, (int) ($get('quantity') ?? 1)) * (float) ($get('unit_price') ?? 0),
                        $get('../../currency') ?: Currency::DEFAULT,
                    )),
                ])->table([
                    TableColumn::make('პროდუქტი')->width('58%'),
                    TableColumn::make('რაოდ.')->width('18%'),
                    TableColumn::make('ჯამი')->width('22%'),
                ])->defaultItems(0)->reorderable(false)->compact()->addActionLabel('+ პროდუქტი')->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $total = (float) ($get('service_amount') ?? 0) + self::paymentProductTotal((array) $state);
                        $set('amount', Money::decimal($total));
                        $splits = array_values((array) ($get('splits') ?? []));
                        if (count($splits) === 1 && ($splits[0]['currency'] ?? $get('currency')) === $get('currency')) {
                            $splits[0]['amount'] = Money::decimal($total);
                            $set('splits', $splits);
                        }
                    }),
                Repeater::make('splits')->hiddenLabel()->live()->schema([
                    Select::make('payment_method')->label('მეთოდი')
                        ->options(PaymentMethod::options())
                        ->native(false)->required(),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)
                        ->step(0.01)
                        ->suffixAction(self::paymentCurrencyToggleAction())
                        ->required()->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('amount_manually_overridden', true)),
                    Hidden::make('currency')->default(fn (Get $get): string => $get('../../currency') ?: Currency::DEFAULT)->live(),
                    Hidden::make('amount_manually_overridden')->default(false),
                    TextInput::make('exchange_rate')->label('კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                        ->required(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                        ->visible(fn (Get $get): bool => ($get('currency') ?: Currency::DEFAULT) !== ($get('../../currency') ?: Currency::DEFAULT))
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            if (($get('currency') ?: Currency::DEFAULT) === ($get('../../currency') ?: Currency::DEFAULT)
                                || ($get('amount_manually_overridden') ?? false)
                                || (float) $state <= 0) {
                                return;
                            }

                            $set('amount', Money::decimal(self::remainingBeforePaymentRow($get) / (float) $state));
                        }),
                ])->table([
                    TableColumn::make('მეთოდი')->width('36%'),
                    TableColumn::make('თანხა')->width('34%'),
                    TableColumn::make('კურსი')->width('28%'),
                ])->defaultItems(1)->minItems(fn (Get $get): int => Money::minorUnits($get('amount')) > 0 ? 1 : 0)->reorderable(false)->compact()
                    ->addActionLabel('+ გადახდის მეთოდი')
                    ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $total = app(PaymentProcessor::class)->distributedMinorUnits((array) ($value ?? []), $get('currency') ?: Currency::DEFAULT);
                        $amount = Money::minorUnits($get('amount'));

                        $tolerance = app(PaymentProcessor::class)->distributionToleranceMinorUnits((array) ($value ?? []), $get('currency') ?: Currency::DEFAULT);

                        if ($total > $amount + $tolerance) {
                            $fail('გადახდის მეთოდებზე განაწილებული თანხა საერთო თანხას აღემატება.');

                            return;
                        }

                        if (abs($total - $amount) > $tolerance) {
                            $fail('გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადახდის საერთო თანხას.');
                        }
                    }]),
                Group::make([
                    Placeholder::make('payment_total_preview')->label('გადასახდელი')
                        ->content(fn (Get $get): string => self::money(
                            Money::decimal($get('amount')),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('split_total_preview')->label('განაწილებული')
                        ->content(fn (Get $get): string => self::money(
                            app(PaymentProcessor::class)->reconciledDistributedAmount(
                                $get('amount'),
                                (array) ($get('splits') ?? []),
                                $get('currency') ?: Currency::DEFAULT,
                            ),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('split_remaining_preview')->label('დარჩენილი')
                        ->content(fn (Get $get): string => self::money(
                            app(PaymentProcessor::class)->remaining($get('amount'), (array) ($get('splits') ?? []), $get('currency') ?: Currency::DEFAULT),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                ])->columns(3),
                Placeholder::make('split_distribution_error')
                    ->hiddenLabel()
                    ->content('განაწილებული თანხა გადახდის საერთო თანხას აღემატება.')
                    ->visible(fn (Get $get): bool => app(PaymentProcessor::class)
                        ->distributedMinorUnits((array) ($get('splits') ?? []), $get('currency') ?: Currency::DEFAULT)
                            > Money::minorUnits($get('amount')) + app(PaymentProcessor::class)
                                ->distributionToleranceMinorUnits((array) ($get('splits') ?? []), $get('currency') ?: Currency::DEFAULT))
                    ->extraAttributes(['class' => 'text-danger-600 dark:text-danger-400']),
            ])->action(function (array $data, $livewire, Action $action): void {
                if (! self::hasValidPaymentContext($livewire)) {
                    $action->halt(shouldRollBackDatabaseTransaction: true);
                }

                $data = self::preparePaymentData($data);

                if (! $livewire->getRecord()?->exists && method_exists($livewire, 'submitPaymentAndCreate')) {
                    $livewire->submitPaymentAndCreate($data);

                    return;
                }

                method_exists($livewire, 'submitCombinedPayment')
                    ? $livewire->submitCombinedPayment($data)
                    : $livewire->submitPayment($data);
            })
            ->disabled(fn ($livewire): bool => $livewire->getRecord()?->exists
                && $livewire->getCurrentRemainingAmount() === 0.0
                && ! (method_exists($livewire, 'hasPendingPayment') && $livewire->hasPendingPayment()));
    }

    private static function hasValidPaymentContext($livewire): bool
    {
        $state = $livewire->form->getRawState();
        $missing = collect([
            'patient_id' => 'პაციენტი',
        ])->filter(fn (string $label, string $field): bool => blank($state[$field] ?? null));

        if ($missing->isEmpty()) {
            return true;
        }

        $message = 'გადახდამდე აირჩიეთ '.implode(' და ', $missing->values()->all()).'.';

        Notification::make()
            ->danger()
            ->title('გადახდა ვერ დასრულდა')
            ->body($message)
            ->send();

        return false;
    }

    /**
     * @param  array{amount: mixed, currency?: string, splits?: array<int|string, array<string, mixed>>}  $data
     * @return array{amount: float, currency?: string, splits: array<int, array<string, mixed>>}
     */
    private static function preparePaymentData(array $data): array
    {
        if (Money::minorUnits($data['amount'] ?? 0) === 0 && blank($data['splits'] ?? [])) {
            $data['amount'] = 0.0;
            $data['splits'] = [];

            return $data;
        }

        $prepared = app(PaymentProcessor::class)->prepare($data['amount'] ?? 0, $data['splits'] ?? [], $data['currency'] ?? Currency::DEFAULT);
        $data['amount'] = $prepared['amount'];
        $data['splits'] = $prepared['rows'];

        return $data;
    }

    /** @param array<int|string, array<string, mixed>> $products */
    private static function paymentProductTotal(array $products): float
    {
        return round((float) collect($products)->sum(
            fn (array $item): float => max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['unit_price'] ?? 0),
        ), 2);
    }

    private static function syncPaymentProductTotalFromRow(Get $get, Set $set): void
    {
        $products = (array) ($get('../../products') ?? []);
        $total = Money::decimal($get('../../service_amount')) + self::paymentProductTotal($products);
        $set('../../amount', Money::decimal($total));

        $splits = array_values((array) ($get('../../splits') ?? []));
        if (count($splits) === 1 && ($splits[0]['currency'] ?? $get('../../currency')) === $get('../../currency')) {
            $splits[0]['amount'] = Money::decimal($total);
            $set('../../splits', $splits);
        }
    }

    private static function money(?float $amount, string $currency = Currency::DEFAULT): string
    {
        return $amount === null ? '—' : Currency::format($amount, $currency);
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function treatmentTotalsBreakdown(array $items, string $baseCurrency, float $additionalBaseAmount = 0): string
    {
        $totals = collect($items)->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null)
            || filled($item['custom_service_name'] ?? null))
            ->groupBy(fn (array $item): string => $item['currency'] ?? $baseCurrency)
            ->map(fn ($currencyItems): float => round((float) $currencyItems->sum(
                fn (array $item): float => max((int) ($item['quantity'] ?? 1), 1) * (float) ($item['unit_price'] ?? 0),
            ), 2));

        if ($additionalBaseAmount > 0) {
            $totals[$baseCurrency] = round((float) ($totals[$baseCurrency] ?? 0) + $additionalBaseAmount, 2);
        }
        if ($totals->isEmpty()) {
            $totals[$baseCurrency] = 0.0;
        }

        return $totals->map(fn (float $amount, string $currency): string => Currency::format($amount, $currency))->implode(' + ');
    }

    private static function paymentCurrencyToggleAction(string $splitsField = 'splits'): Action
    {
        return Action::make('togglePaymentCurrency')
            ->label(fn (Get $get): string => Currency::symbol($get('currency')))
            ->tooltip('₾ / $')
            ->link()
            ->color('gray')
            ->extraAttributes(['class' => 'min-w-8 justify-center font-semibold text-gray-700 dark:text-gray-200'])
            ->action(function (Get $get, Set $set) use ($splitsField): void {
                $debtCurrency = $get('../../currency') ?: Currency::DEFAULT;
                $targetCurrency = ($get('currency') ?: $debtCurrency) === 'GEL' ? 'USD' : 'GEL';
                $remaining = self::remainingBeforePaymentRow($get, $splitsField);

                $set('currency', $targetCurrency);
                $set('amount_manually_overridden', false);

                if ($targetCurrency === $debtCurrency) {
                    $set('exchange_rate', null);
                    $set('amount', Money::decimal($remaining));

                    return;
                }

                try {
                    $usdGel = app(NbgExchangeRate::class)->usdGel();
                    $rate = $targetCurrency === 'USD' && $debtCurrency === 'GEL'
                        ? $usdGel
                        : round(1 / $usdGel, 6);
                    $set('exchange_rate', $rate);
                    $set('amount', Money::decimal($remaining / $rate));
                } catch (Throwable $exception) {
                    report($exception);
                    $set('exchange_rate', null);

                    Notification::make()
                        ->warning()
                        ->title('NBG-ის კურსი ვერ ჩაიტვირთა')
                        ->body('მიუთითეთ USD/GEL კურსი ხელით. გადახდის გაგრძელება შეგიძლიათ.')
                        ->send();
                }
            });
    }

    private static function remainingBeforePaymentRow(Get $get, string $splitsField = 'splits'): float
    {
        $debtCurrency = $get('../../currency') ?: Currency::DEFAULT;
        $totalMinor = Money::minorUnits($get('../../amount'));
        $distributedMinor = app(PaymentProcessor::class)->distributedMinorUnits(
            (array) ($get('../../'.$splitsField) ?? []),
            $debtCurrency,
        );
        $currentCurrency = $get('currency') ?: $debtCurrency;
        $currentRate = $currentCurrency === $debtCurrency ? 1 : (float) ($get('exchange_rate') ?? 0);
        $currentMinor = Money::minorUnits((float) ($get('amount') ?? 0) * $currentRate);

        return max(0, $totalMinor - max(0, $distributedMinor - $currentMinor)) / 100;
    }

    private static function currencyToggleAction(string $currencyPath): Action
    {
        return Action::make('toggleCurrency')
            ->label(fn (Get $get): string => Currency::symbol($get($currencyPath)))
            ->tooltip('₾ / $')
            ->link()
            ->color('gray')
            ->extraAttributes([
                'class' => 'min-w-8 justify-center font-semibold text-gray-700 dark:text-gray-200',
            ])
            ->action(function (Get $get, Set $set) use ($currencyPath): void {
                $set(
                    $currencyPath,
                    ($get($currencyPath) ?: Currency::DEFAULT) === 'GEL' ? 'USD' : 'GEL',
                );
            });
    }

    /** @param array<int|string, array<string, mixed>> $expenses */
    private static function directExpensesTotal(array $expenses, string $currency): float
    {
        return round(collect($expenses)
            ->where('currency', $currency)
            ->sum(fn (array $expense): float => (float) ($expense['amount'] ?? 0)), 2);
    }

    /** @param array<int|string, array<string, mixed>> $splits */
    private static function paymentSplitsTotal(array $splits, string $debtCurrency = Currency::DEFAULT): float
    {
        return app(PaymentProcessor::class)->distributedAmount($splits, $debtCurrency);
    }
}
