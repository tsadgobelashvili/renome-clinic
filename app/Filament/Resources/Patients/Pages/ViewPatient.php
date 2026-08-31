<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Patients\Actions\ViewTreatmentPlansAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Schemas\PatientInfolist;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    public function getSubheading(): ?string
    {
        return 'ისტორიის '.$this->record->formatted_patient_number;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'renome-patient-profile-page'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('patientInformation'),
            $this->getRelationManagersContentComponent(),
            EmbeddedSchema::make('patientSummary'),
        ]);
    }

    public function patientInformation(Schema $schema): Schema
    {
        return $schema->record($this->record)->components([
            PatientInfolist::informationSection(),
        ]);
    }

    public function patientSummary(Schema $schema): Schema
    {
        return $schema->record($this->record)->components([
            PatientInfolist::doctorsSection(),
            PatientInfolist::financialSection(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createVisit')->label('+ ახალი ვიზიტი')
                ->url(fn (): string => VisitResource::getUrl('create', ['patient_id' => $this->record->getKey()])),
            $this->paymentAction(),
            ViewTreatmentPlansAction::make($this->record),
            ActionGroup::make([
                Action::make('historyPdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (): string => route('patients.history.pdf', $this->record)),
                Action::make('historyWord')
                    ->label('Word')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (): string => route('patients.history.word', $this->record)),
            ])
                ->label('ისტორიის ექსპორტი')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
            EditAction::make(),
        ];
    }

    private function paymentAction(): Action
    {
        return Action::make('makePayment')
            ->label('+ გადახდა')
            ->modalHeading('გადახდის დამატება')
            ->modalWidth('md')
            ->modalSubmitActionLabel('დადასტურება')
            ->schema([
                DatePicker::make('payment_date')
                    ->label('გადახდის თარიღი')
                    ->default(today())
                    ->displayFormat('d.m.Y')
                    ->required(),
                Select::make('visit_id')
                    ->label('ვიზიტი')
                    ->options(fn (): array => $this->outstandingVisitOptions())
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $visit = $this->outstandingVisit((int) $state);
                        $amount = $visit ? app(PaymentProcessor::class)->amountDue($visit->net_amount, $visit->paid_amount) : 0;

                        $set('currency', $visit?->currency ?: Currency::DEFAULT);
                        $set('amount', $amount ?: null);
                        $set('splits', $amount > 0 ? [['payment_method' => 'cash', 'amount' => $amount]] : []);
                    }),
                Hidden::make('currency')->default(Currency::DEFAULT),
                TextInput::make('amount')
                    ->label('თანხა')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->suffix(fn (Get $get): string => Currency::symbol($get('currency') ?: Currency::DEFAULT))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $splits = array_values((array) ($get('splits') ?? []));

                        if (count($splits) === 1) {
                            $splits[0]['amount'] = $state;
                            $set('splits', $splits);
                        }
                    }),
                Repeater::make('splits')
                    ->label('გადახდის მეთოდები')
                    ->live()
                    ->schema([
                        Select::make('payment_method')
                            ->label('მეთოდი')
                            ->options(PaymentMethod::options())
                            ->native(false)
                            ->distinct()
                            ->required(),
                        TextInput::make('amount')
                            ->label('თანხა')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->suffix(fn (Get $get): string => Currency::symbol($get('../../currency') ?: Currency::DEFAULT))
                            ->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->compact()
                    ->addActionLabel('+ გადახდის მეთოდი'),
                Group::make([
                    Placeholder::make('distributed')
                        ->label('განაწილებული')
                        ->content(fn (Get $get): string => Currency::format(
                            app(PaymentProcessor::class)->distributedAmount((array) ($get('splits') ?? [])),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                    Placeholder::make('remaining')
                        ->label('დარჩენილი')
                        ->content(fn (Get $get): string => Currency::format(
                            app(PaymentProcessor::class)->remaining($get('amount'), (array) ($get('splits') ?? [])),
                            $get('currency') ?: Currency::DEFAULT,
                        )),
                ])->columns(2),
            ])
            ->action(function (array $data): void {
                $visit = $this->outstandingVisit((int) ($data['visit_id'] ?? 0));

                if (! $visit) {
                    throw ValidationException::withMessages([
                        'visit_id' => 'არჩეული Visit აღარ არის გადასახდელი ან ამ პაციენტს არ ეკუთვნის.',
                    ]);
                }

                app(PaymentProcessor::class)->process([
                    'visit_id' => $visit->getKey(),
                    'amount' => $data['amount'],
                    'currency' => $visit->currency,
                    'payment_date' => $data['payment_date'],
                ], $data['splits']);

                Notification::make()->success()->title('გადახდა წარმატებით დაემატა.')->send();
                $this->record = $this->record->fresh();
                $this->dispatch('$refresh');
            });
    }

    /** @return array<int, string> */
    private function outstandingVisitOptions(): array
    {
        return $this->record->visits()
            ->with(['patient.patientGroup', 'doctor', 'treatmentCaseItems.treatmentCase', 'payments'])
            ->latest('visit_date')
            ->latest('id')
            ->get()
            ->filter(fn (Visit $visit): bool => ($visit->remaining_amount ?? 0) > 0)
            ->mapWithKeys(fn (Visit $visit): array => [$visit->getKey() => implode(' — ', [
                $visit->visit_date->format('d.m.Y'),
                $visit->doctor?->full_name ?? '—',
                $this->manipulationsSummary($visit),
                'დარჩენილი '.Currency::format($visit->remaining_amount, $visit->currency),
            ])])
            ->all();
    }

    private function outstandingVisit(int $visitId): ?Visit
    {
        $visit = $this->record->visits()
            ->with(['patient.patientGroup', 'payments'])
            ->find($visitId);

        return $visit && ($visit->remaining_amount ?? 0) > 0 ? $visit : null;
    }

    private function manipulationsSummary(Visit $visit): string
    {
        $items = $visit->treatmentCaseItems;
        $first = $items->first();

        return $first
            ? $first->display_name.' ×'.(int) $first->quantity.($items->count() > 1 ? ' +'.($items->count() - 1) : '')
            : 'მანიპულაცია არ არის';
    }
}
