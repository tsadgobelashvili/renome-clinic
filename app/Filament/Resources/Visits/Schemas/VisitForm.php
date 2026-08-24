<?php

namespace App\Filament\Resources\Visits\Schemas;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class VisitForm
{
    /** @param array<string, mixed> $data */
    private static function normalizeTreatmentItemQuantity(array $data): array
    {
        if (filled($data['treatment_case_id'] ?? null) && blank($data['quantity'] ?? null)) {
            $data['quantity'] = 1;
        }

        return $data;
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
                        ));
                    }),
            ])->columns(1)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('visit_type') === 'consultation'),

            Hidden::make('treatment_estimate_id'),
            Hidden::make('treatment_estimate_option_id'),

            Group::make([
                Actions::make([self::tomographyAction()]),
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
                ->label(fn (Get $get): string => $get('visit_type') === 'consultation' ? 'ტომოგრაფია' : 'შესრულებული სამუშაო')
                ->live()
                ->extraAttributes(['class' => 'renome-visit-work-items'])
                ->afterStateUpdated(fn (mixed $state, Get $get, Set $set, ?Visit $record): mixed => $set(
                    'total_price',
                    Visit::totalFromTreatmentItemState(
                        (array) ($state ?? []),
                        $record?->total_price,
                        $get('visit_type') === 'consultation' ? $get('consultation_fee') : 0,
                    ),
                ))
                ->schema([
                    Select::make('treatment_case_id')->label(fn (Get $get): string => $get('../../visit_type') === 'consultation' ? 'სერვისი' : 'მანიპულაცია')->relationship(
                        name: 'treatmentCase', titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                            ->where('is_active', true)
                            ->when(
                                $get('../../visit_type') === 'consultation',
                                fn (Builder $query): Builder => $query->where('category', 'tomography'),
                            ),
                    )->getOptionLabelFromRecordUsing(fn (TreatmentCase $record): string => $record->name)
                        ->searchable(['name'])->preload(false)
                        ->createOptionForm([
                            TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                            Select::make('category')->label('კატეგორია')
                                ->options(TreatmentCase::CATEGORIES)->native(false)->required(),
                            TextInput::make('default_price')->label('ფასი')->numeric()->minValue(0)
                                ->step(0.01)->suffix('₾'),
                        ])
                        ->createOptionModalHeading('ახალი მანიპულაცია')
                        ->createOptionUsing(function (array $data): int {
                            $name = trim($data['name']);
                            $existing = TreatmentCase::query()
                                ->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

                            if ($existing) {
                                Notification::make()->warning()->title('ასეთი მანიპულაცია უკვე არსებობს.')
                                    ->body('არსებული ჩანაწერი ავტომატურად აირჩა.')->send();

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
                        })
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            if (filled($state) && blank($get('quantity'))) {
                                $set('quantity', 1);
                            }

                            $set('unit_price', TreatmentCase::query()->find($state)?->default_price);
                            $set('../../total_price', Visit::totalFromTreatmentItemState(
                                (array) ($get('../../treatmentCaseItems') ?? []),
                                null,
                                $get('../../visit_type') === 'consultation' ? $get('../../consultation_fee') : 0,
                            ));
                        })
                        ->required(),
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
                        ->suffixAction(self::currencyToggleAction('../../currency'))
                        ->required()
                        ->validationMessages([
                            'required' => 'მიუთითეთ შესრულებული მანიპულაციის ფასი.',
                        ])
                        ->live(debounce: 300),
                    Placeholder::make('manipulation_total_preview')->label('ჯამი')
                        ->extraAttributes(['class' => 'renome-visit-work-item-total'])
                        ->content(fn (Get $get): string => self::money(
                            (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                        )),
                    TextInput::make('teeth')->label('კბილები / უბანი')->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('../../visit_type') === 'treatment'),
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

                                return self::money($expenses, $currency).' · ნეტო '.self::money($total - $expenses, $currency);
                            }),
                        Repeater::make('directExpenses')->relationship()->hiddenLabel()->live()
                            ->extraAttributes(['class' => 'renome-visit-expenses'])
                            ->schema([
                                TextInput::make('name')->label('ხარჯი')->placeholder('ხარჯის დასახელება')
                                    ->required()->maxLength(255),
                                TextInput::make('amount')->label('რაოდენობა')->numeric()->minValue(0.01)
                                    ->step(0.01)->suffixAction(self::currencyToggleAction('currency'))
                                    ->required()->live(debounce: 300),
                                Hidden::make('currency')->default(Currency::DEFAULT),
                            ])
                            ->table([
                                TableColumn::make('ხარჯი')->width('62%'),
                                TableColumn::make('რაოდენობა')->width('36%'),
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
                        TableColumn::make('მანიპულაცია')->width('23%'),
                        TableColumn::make('რაოდენობა')->width('10%'),
                        TableColumn::make('ფასი')->width('13%'),
                        TableColumn::make('ჯამი')->width('14%'),
                        TableColumn::make('კბილები / უბანი')->width('15%'),
                        TableColumn::make('ხარჯი')->width('20%'),
                    ])
                ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $fingerprints = collect($value ?? [])->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null))
                        ->map(fn (array $item): string => hash('sha256', json_encode([
                            (int) $item['treatment_case_id'], (int) ($item['quantity'] ?? 1),
                            filled($item['teeth'] ?? null) ? trim((string) $item['teeth']) : null,
                            filled($item['comment'] ?? null) ? trim((string) $item['comment']) : null,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

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
                ->defaultItems(1)->reorderable(false)->compact()->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('visit_type') === 'treatment'),

            Group::make([
                Hidden::make('total_price'),
                Placeholder::make('visit_total_preview')->label('სრული ღირებულება')
                    ->content(fn (Get $get): string => self::money(
                        (float) ($get('total_price') ?? 0),
                        $get('currency') ?: Currency::DEFAULT,
                    )),
                Hidden::make('currency')->default(Currency::DEFAULT)->live(),
                Group::make([
                    TextInput::make('discount_value')->label('ფასდაკლება')->numeric()->minValue(0)
                        ->step(0.01)->default(0)->required()->live(onBlur: true)->columnSpan(3)
                        ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if ($get('discount_type') === 'percent' && (float) $value > 100) {
                                $fail('ფასდაკლება 100%-ზე მეტი ვერ იქნება.');
                            }
                            if (filled($get('total_price')) && $get('discount_type') === 'amount' && (float) $value > (float) $get('total_price')) {
                                $fail('ფასდაკლება ღირებულებას ვერ გადააჭარბებს.');
                            }
                        }]),
                    Select::make('discount_type')->label('ერთეული')->options(['amount' => '₾', 'percent' => '%'])
                        ->default('amount')->required()->native(false)->live()->columnSpan(1),
                ])->columns(4)
                    ->visible(fn (Get $get): bool => $get('visit_type') === 'treatment'),
                Group::make([
                    Placeholder::make('remaining_amount_preview')->label('გადასახდელი')
                        ->content(fn ($livewire, Get $get): string => self::money(
                            $livewire->getCurrentRemainingAmount(),
                            $get('currency') ?: Currency::DEFAULT,
                        ))->columnSpan(3),
                    Actions::make([self::paymentAction()])->columnSpan(1),
                ])->columns(4),
            ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),

            Textarea::make('comment')->label('კომენტარი')->rows(2)->columnSpanFull(),

            Actions::make([
                Action::make('createEstimate')
                    ->label(fn (?Visit $record): string => $record?->treatmentEstimates()->exists()
                        ? 'გეგმის რედაქტირება'
                        : '+ გეგმა')
                    ->button()
                    ->icon(Heroicon::Calculator)
                    ->action(fn ($livewire) => $livewire->openTreatmentEstimate())
                    ->disabled(fn (Get $get): bool => blank($get('patient_id'))),
            ])->visible(fn (Get $get): bool => $get('visit_type') === 'consultation')->columnSpanFull(),

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
            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
            ->searchable(['first_name', 'last_name', 'phone', 'personal_id'])->preload(false)
            ->createOptionForm([
                TextInput::make('first_name')->label('სახელი')->required()->maxLength(100),
                TextInput::make('last_name')->label('გვარი')->required()->maxLength(100),
                TextInput::make('phone')->label('მობილური')->tel()->required()->maxLength(30),
                TextInput::make('personal_id')->label('პირადი ნომერი')->maxLength(20)
                    ->unique(table: Patient::class, column: 'personal_id')
                    ->validationMessages([
                        'unique' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.',
                    ]),
            ])->createOptionModalHeading('ახალი პაციენტის შექმნა')
            ->createOptionUsing(function (array $data): int {
                return Patient::create([
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'phone' => trim($data['phone']),
                    'personal_id' => filled($data['personal_id'] ?? null)
                        ? trim($data['personal_id'])
                        : null,
                ])->getKey();
            })->live()->afterStateUpdated(function (Set $set): void {
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
            ->getSearchResultsUsing(fn (string $search): array => Doctor::query()->where('is_active', true)
                ->searchByName($search)->orderBy('first_name')->orderBy('last_name')->limit(50)->get()
                ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])->all())
            ->preload(false)->required();
    }

    private static function tomographyAction(): Action
    {
        return Action::make('manageTomography')
            ->label(fn (Get $get): string => self::hasTomographyItems(
                (array) ($get('treatmentCaseItems') ?? []),
            ) ? 'ტომოგრაფიის რედაქტირება' : '+ 3D CT')
            ->button()
            ->modalHeading('ტომოგრაფია')
            ->modalWidth('lg')
            ->modalSubmitActionLabel('დადასტურება')
            ->databaseTransaction()
            ->fillForm(function ($livewire): array {
                $state = $livewire->form->getRawState();
                $items = collect($state['treatmentCaseItems'] ?? [])
                    ->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null))
                    ->all();
                $due = self::tomographyAmountDue($livewire, $items);

                return [
                    'consultation_source' => $state['consultation_source'] ?? 'our_patient',
                    'tomographyItems' => $items ?: [['quantity' => 1]],
                    'paymentSplits' => $due > 0
                        ? [['payment_method' => 'cash', 'amount' => $due]]
                        : [],
                ];
            })
            ->schema([
                Select::make('consultation_source')
                    ->label('წყარო')
                    ->options(Visit::CONSULTATION_SOURCES)
                    ->default('our_patient')
                    ->native(false)
                    ->required(),
                Repeater::make('tomographyItems')
                    ->hiddenLabel()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set, $livewire): void {
                        $splits = collect($get('paymentSplits') ?? []);

                        if ($splits->count() > 1) {
                            return;
                        }

                        $due = self::tomographyAmountDue($livewire, (array) ($state ?? []));
                        $set('paymentSplits', $due > 0
                            ? [[
                                'payment_method' => $splits->first()['payment_method'] ?? 'cash',
                                'amount' => $due,
                            ]]
                            : []);
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
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                if (blank($get('quantity'))) {
                                    $set('quantity', 1);
                                }

                                $set('unit_price', TreatmentCase::query()
                                    ->where('category', 'tomography')
                                    ->find($state)?->default_price);
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
                            ->suffix(fn ($livewire): string => Currency::symbol(
                                $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
                            ))
                            ->required()
                            ->live(debounce: 300),
                        Placeholder::make('tomography_line_total')
                            ->label('ჯამი')
                            ->content(fn (Get $get, $livewire): string => self::money(
                                (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                                $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
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
                    ->deleteAction(fn (Action $action): Action => $action->tooltip('წაშლა')),
                Repeater::make('paymentSplits')
                    ->label('გადახდა')
                    ->live()
                    ->schema([
                        Select::make('payment_method')
                            ->label('მეთოდი')
                            ->options([
                                'cash' => 'ნაღდი',
                                'card' => 'ბარათი',
                            ])
                            ->native(false)
                            ->required()
                            ->distinct(),
                        TextInput::make('amount')
                            ->label('თანხა')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->suffix(fn ($livewire): string => Currency::symbol(
                                $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
                            ))
                            ->required()
                            ->live(debounce: 300),
                    ])
                    ->table([
                        TableColumn::make('მეთოდი')->width('50%'),
                        TableColumn::make('თანხა')->width('50%'),
                    ])
                    ->defaultItems(0)
                    ->minItems(0)
                    ->maxItems(2)
                    ->reorderable(false)
                    ->compact()
                    ->addAction(fn (Action $action): Action => $action
                        ->label('+ გადახდის მეთოდი')
                        ->link())
                    ->rules([fn (Get $get, $livewire) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $livewire): void {
                        if (blank($value)) {
                            return;
                        }

                        $due = self::tomographyAmountDue(
                            $livewire,
                            (array) ($get('tomographyItems') ?? []),
                        );
                        $splitTotal = collect($value)
                            ->sum(fn (array $split): int => Payment::toCents($split['amount'] ?? 0));

                        if ($splitTotal !== Payment::toCents($due)) {
                            $fail('გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადასახდელ თანხას.');
                        }
                    }]),
                Group::make([
                    Placeholder::make('tomography_due_preview')
                        ->label('გადასახდელი')
                        ->content(fn (Get $get, $livewire): string => self::money(
                            self::tomographyAmountDue($livewire, (array) ($get('tomographyItems') ?? [])),
                            $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
                        )),
                    Placeholder::make('tomography_distributed_preview')
                        ->label('განაწილებული')
                        ->content(fn (Get $get, $livewire): string => self::money(
                            self::paymentSplitsTotal((array) ($get('paymentSplits') ?? [])),
                            $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
                        )),
                    Placeholder::make('tomography_remaining_preview')
                        ->label('დარჩენილი')
                        ->content(fn (Get $get, $livewire): string => self::money(
                            max(0, self::tomographyAmountDue(
                                $livewire,
                                (array) ($get('tomographyItems') ?? []),
                            ) - self::paymentSplitsTotal((array) ($get('paymentSplits') ?? []))),
                            $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT,
                        )),
                ])->columns(3),
            ])
            ->action(function (array $data, $livewire, Action $action): void {
                if (! self::hasValidPaymentContext($livewire)) {
                    $action->halt(shouldRollBackDatabaseTransaction: true);
                }

                $items = collect($data['tomographyItems'] ?? [])
                    ->filter(fn (array $item): bool => filled($item['treatment_case_id'] ?? null))
                    ->map(fn (array $item): array => self::normalizeTreatmentItemQuantity($item))
                    ->all();
                $state = $livewire->form->getRawState();
                $state['consultation_source'] = $data['consultation_source'] ?? 'our_patient';
                $state['treatmentCaseItems'] = $items;
                $state['total_price'] = Visit::totalFromTreatmentItemState(
                    $items,
                    null,
                    $state['consultation_fee'] ?? 0,
                );
                $due = self::tomographyAmountDue($livewire, $items, $state['total_price']);
                $splits = collect($data['paymentSplits'] ?? [])
                    ->map(fn (array $split): array => [
                        'payment_method' => $split['payment_method'],
                        'amount' => Payment::toCents($split['amount'] ?? 0) / 100,
                    ])
                    ->values()
                    ->all();

                if ($splits !== []) {
                    Payment::validateSplits($due, $splits);
                }

                // Assign the existing form state directly. Calling form->fill() here would
                // reload relationship records on Edit and undo a removal made in the modal.
                $livewire->data = $state;

                if ($splits !== []) {
                    $livewire->submitPayment([
                        'amount' => $due,
                        'currency' => $state['currency'] ?? Currency::DEFAULT,
                        'splits' => $splits,
                    ]);

                    return;
                }

                if ($livewire->getRecord()?->exists) {
                    $livewire->save(shouldRedirect: false);

                    return;
                }

                $livewire->create();
            });
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function tomographyAmountDue($livewire, array $items, ?float $total = null): float
    {
        $state = $livewire->form->getRawState();
        $currency = $state['currency'] ?? Currency::DEFAULT;
        $total ??= Visit::totalFromTreatmentItemState(
            $items,
            null,
            $state['consultation_fee'] ?? 0,
        );
        $paid = $livewire->getRecord()?->payments()
            ->where('currency', $currency)
            ->sum('amount') ?? 0;

        return Payment::toCents(max(0, $total - (float) $paid)) / 100;
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
        return Action::make('makePayment')->label('გადახდა')->modalHeading('გადახდა')->modalWidth('md')
            ->modalSubmitActionLabel('დადასტურება')
            ->databaseTransaction()
            ->fillForm(function ($livewire): array {
                $amount = $livewire->getCurrentRemainingAmount();
                $currency = $livewire->form->getRawState()['currency'] ?? Currency::DEFAULT;

                return [
                    'amount' => $amount,
                    'currency' => $currency,
                    'splits' => [['payment_method' => 'cash', 'amount' => $amount]],
                ];
            })
            ->schema([
                Group::make([
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)
                        ->step(0.01)->suffixAction(self::currencyToggleAction('currency'))
                        ->required()->live()->columnSpanFull(),
                    Hidden::make('currency')->default(Currency::DEFAULT)->live(),
                ])->columns(4),
                Repeater::make('splits')->hiddenLabel()->live()->schema([
                    Select::make('payment_method')->label('მეთოდი')->options([
                        'cash' => 'ნაღდი', 'card' => 'ბარათი', 'transfer' => 'გადარიცხვა',
                    ])->native(false)->required()->distinct(),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)
                        ->step(0.01)
                        ->suffixAction(self::currencyToggleAction('../../currency'))
                        ->required()->live(),
                ])->table([
                    TableColumn::make('მეთოდი')->width('50%'),
                    TableColumn::make('თანხა')->width('50%'),
                ])->defaultItems(1)->minItems(1)->reorderable(false)->compact()
                    ->addActionLabel('+ გადახდის მეთოდი')
                    ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $total = collect($value ?? [])->sum(fn (array $split): int => Payment::toCents($split['amount'] ?? 0));

                        if ($total !== Payment::toCents($get('amount'))) {
                            $fail('გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადახდის საერთო თანხას.');
                        }
                    }]),
                Group::make([
                    Placeholder::make('payment_total_preview')->label('გადასახდელი')
                        ->content(fn (Get $get): string => self::money(
                            Payment::toCents($get('amount')) / 100,
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('split_total_preview')->label('განაწილებული')
                        ->content(fn (Get $get): string => self::money(
                            self::paymentSplitsTotal($get('splits') ?? []),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('split_remaining_preview')->label('დარჩენილი')
                        ->content(fn (Get $get): string => self::money(
                            max(0, Payment::toCents($get('amount')) / 100 - self::paymentSplitsTotal($get('splits') ?? [])),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                ])->columns(3),
            ])->action(function (array $data, $livewire, Action $action): void {
                if (! self::hasValidPaymentContext($livewire)) {
                    $action->halt(shouldRollBackDatabaseTransaction: true);
                }

                $data = self::normalizePaymentData($data);
                Payment::validateSplits($data['amount'], $data['splits']);

                $livewire->submitPayment($data);
            })
            ->disabled(fn ($livewire): bool => $livewire->getCurrentRemainingAmount() === 0.0);
    }

    private static function hasValidPaymentContext($livewire): bool
    {
        $state = $livewire->form->getRawState();
        $missing = collect([
            'patient_id' => 'პაციენტი',
            'doctor_id' => 'ექიმი',
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
    private static function normalizePaymentData(array $data): array
    {
        $data['amount'] = Payment::toCents($data['amount'] ?? 0) / 100;
        $data['splits'] = collect($data['splits'] ?? [])
            ->map(function (array $split): array {
                $split['amount'] = Payment::toCents($split['amount'] ?? 0) / 100;

                return $split;
            })
            ->values()
            ->all();

        return $data;
    }

    private static function money(?float $amount, string $currency = Currency::DEFAULT): string
    {
        return $amount === null ? '—' : Currency::format($amount, $currency);
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
    private static function paymentSplitsTotal(array $splits): float
    {
        return collect($splits)->sum(fn (array $split): int => Payment::toCents($split['amount'] ?? 0)) / 100;
    }
}
