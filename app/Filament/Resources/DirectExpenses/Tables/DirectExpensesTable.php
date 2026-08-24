<?php

namespace App\Filament\Resources\DirectExpenses\Tables;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('visit.visit_date')->label('თარიღი')->date('d.m.Y')->sortable()->searchable(false),
                TextColumn::make('visit.patient.first_name')->label('პაციენტი')
                    ->formatStateUsing(fn (VisitTreatmentCase $record): string => $record->visit->patient->full_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'visit.patient',
                        fn (Builder $patient): Builder => $patient
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"),
                    )),
                TextColumn::make('visit.doctor.first_name')->label('ექიმი')
                    ->formatStateUsing(fn (VisitTreatmentCase $record): string => $record->visit->doctor->full_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'visit.doctor',
                        fn (Builder $doctor): Builder => $doctor
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"),
                    )),
                TextColumn::make('treatmentCase.name')->label('მანიპულაცია')->searchable()->sortable(),
                TextColumn::make('quantity')->label('რაოდ.')->numeric()->alignEnd(),
                TextColumn::make('manipulation_total')->label('შესრულებული')
                    ->formatStateUsing(fn ($state, VisitTreatmentCase $record): string => Currency::format(
                        $state,
                        $record->visit->currency,
                    ))->alignEnd(),
                TextInputColumn::make('quick_expense')->label('პირდაპირი ხარჯი')
                    ->type('number')->step(0.01)->rules(['nullable', 'numeric', 'min:0'])
                    ->suffix(fn (VisitTreatmentCase $record): string => Currency::symbol($record->visit->currency))
                    ->state(fn (VisitTreatmentCase $record): float => $record->direct_expenses_total)
                    ->disabled(fn (VisitTreatmentCase $record): bool => $record->directExpenses()
                        ->where('currency', $record->visit->currency ?: Currency::DEFAULT)
                        ->count() > 1)
                    ->updateStateUsing(fn (VisitTreatmentCase $record, mixed $state): float => self::saveQuickExpense($record, $state)),
                TextColumn::make('net_amount')->label('ნეტო')
                    ->formatStateUsing(fn ($state, VisitTreatmentCase $record): string => Currency::format(
                        $state,
                        $record->visit->currency,
                    ))->alignEnd(),
            ])
            ->filters([
                TernaryFilter::make('expense_status')->label('ხარჯი შევსებულია')
                    ->placeholder('ყველა')
                    ->trueLabel('შევსებულია')->falseLabel('არ არის შევსებული')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('directExpenses'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('directExpenses'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('visit_date')->label('თარიღის პერიოდი')->schema([
                    DatePicker::make('from')->label('თარიღიდან'),
                    DatePicker::make('until')->label('თარიღამდე'),
                ])->columns(2)->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query
                        ->whereHas('visit', fn (Builder $visit): Builder => $visit->whereDate('visit_date', '>=', $date)))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query
                        ->whereHas('visit', fn (Builder $visit): Builder => $visit->whereDate('visit_date', '<=', $date)))),
                SelectFilter::make('doctor')->label('ექიმი')
                    ->options(fn (): array => Doctor::query()->orderBy('first_name')->orderBy('last_name')->get()
                        ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])->all())
                    ->searchable()->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $query, mixed $doctorId): Builder => $query
                        ->whereHas('visit', fn (Builder $visit): Builder => $visit->where('doctor_id', $doctorId)))),
                SelectFilter::make('patient')->label('პაციენტი')
                    ->options(fn (): array => Patient::query()->orderBy('first_name')->orderBy('last_name')->get()
                        ->mapWithKeys(fn (Patient $patient): array => [$patient->getKey() => $patient->full_name])->all())
                    ->searchable()->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $query, mixed $patientId): Builder => $query
                        ->whereHas('visit', fn (Builder $visit): Builder => $visit->where('patient_id', $patientId)))),
                SelectFilter::make('treatment_case_id')->label('მანიპულაცია')
                    ->options(fn (): array => TreatmentCase::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('manageExpenses')->label('ხარჯები')->icon('heroicon-o-pencil-square')->iconButton()
                    ->tooltip('ხარჯების მართვა')->modalHeading('პირდაპირი ხარჯები')->modalWidth('md')
                    ->fillForm(fn (VisitTreatmentCase $record): array => [
                        'expenses' => $record->directExpenses()->oldest('id')
                            ->get(['id', 'name', 'amount', 'currency'])->toArray(),
                    ])->schema([
                        Repeater::make('expenses')->hiddenLabel()->schema([
                            Hidden::make('id'),
                            TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                            TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)
                                ->step(0.01)->suffixAction(self::currencyToggleAction())->required(),
                            Hidden::make('currency')->default(Currency::DEFAULT),
                        ])->columns(3)->defaultItems(0)->addActionLabel('+ ხარჯი')->compact(),
                    ])->action(fn (VisitTreatmentCase $record, array $data): mixed => self::saveExpenseBreakdown(
                        $record,
                        $data['expenses'] ?? [],
                    )),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc(Visit::query()->select('visit_date')
                    ->whereColumn('visits.id', 'visit_treatment_cases.visit_id'))
                ->orderByDesc('visit_treatment_cases.id'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    private static function saveQuickExpense(VisitTreatmentCase $record, mixed $state): float
    {
        $amount = round((float) ($state ?? 0), 2);

        $currency = $record->visit->currency ?: Currency::DEFAULT;

        if ($record->directExpenses()->where('currency', $currency)->count() > 1) {
            throw ValidationException::withMessages(['quick_expense' => 'რამდენიმე ხარჯის შემთხვევაში გამოიყენეთ რედაქტირების ღილაკი.']);
        }

        if ($amount > $record->manipulation_total) {
            throw ValidationException::withMessages(['quick_expense' => 'პირდაპირი ხარჯი ვერ იქნება მანიპულაციის თანხაზე მეტი.']);
        }

        $expense = $record->directExpenses()->where('currency', $currency)->first();

        if ($amount <= 0) {
            $expense?->delete();

            return 0;
        }

        if ($expense) {
            $expense->update(['amount' => $amount]);
        } else {
            $record->directExpenses()->create([
                'name' => 'პირდაპირი ხარჯი',
                'amount' => $amount,
                'currency' => $currency,
            ]);
        }

        return $amount;
    }

    private static function currencyToggleAction(): Action
    {
        return Action::make('toggleCurrency')
            ->label(fn (Get $get): string => Currency::symbol($get('currency')))
            ->tooltip('₾ / $')
            ->link()
            ->color('gray')
            ->extraAttributes([
                'class' => 'min-w-8 justify-center font-semibold text-gray-700 dark:text-gray-200',
            ])
            ->action(fn (Get $get, Set $set): mixed => $set(
                'currency',
                ($get('currency') ?: Currency::DEFAULT) === 'GEL' ? 'USD' : 'GEL',
            ));
    }

    /** @param array<int, array<string, mixed>> $expenses */
    private static function saveExpenseBreakdown(VisitTreatmentCase $record, array $expenses): void
    {
        $visitCurrency = $record->visit->currency ?: Currency::DEFAULT;
        $total = round(collect($expenses)
            ->where('currency', $visitCurrency)
            ->sum(fn (array $expense): float => (float) ($expense['amount'] ?? 0)), 2);

        if ($total > $record->manipulation_total) {
            throw ValidationException::withMessages(['expenses' => 'პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.']);
        }

        DB::transaction(function () use ($record, $expenses): void {
            $keptIds = collect($expenses)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
            $record->directExpenses()->when($keptIds, fn (Builder $query) => $query->whereKeyNot($keptIds))->delete();

            $existing = $record->directExpenses()->get()->keyBy('id');
            $ordered = collect($expenses)->sortBy(fn (array $expense): float => (float) ($expense['amount'] ?? 0));

            foreach ($ordered as $data) {
                $attributes = [
                    'name' => $data['name'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'GEL',
                ];
                $expense = filled($data['id'] ?? null) ? $existing->get((int) $data['id']) : null;

                $expense ? $expense->update($attributes) : $record->directExpenses()->create($attributes);
            }
        });
    }
}
