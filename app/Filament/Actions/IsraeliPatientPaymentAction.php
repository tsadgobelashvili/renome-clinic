<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Services\IsraeliPatientPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class IsraeliPatientPaymentAction
{
    public static function make(): Action
    {
        return Action::make('addPatientPayment')
            ->label('+ Payment')
            ->icon('heroicon-o-banknotes')
            ->modalHeading('ისრაელის პაციენტის გადახდა')
            ->modalSubmitActionLabel('შენახვა')
            ->schema([
                Hidden::make('patient_id'),
                TextInput::make('patient_search')
                    ->label('პაციენტი')
                    ->placeholder('ჩაწერეთ სახელი ან გვარი')
                    ->helperText('აირჩიეთ შეთავაზებიდან; თუ პაციენტი ახალია, შეავსეთ ქვემოთ სახელი და გვარი.')
                    ->datalist(fn (Get $get): array => app(IsraeliPatientPaymentService::class)
                        ->suggestions($get('patient_search')))
                    ->live(debounce: 300)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set(
                        'patient_id',
                        app(IsraeliPatientPaymentService::class)->patientIdFromLabel($state),
                    ))
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    TextInput::make('first_name')
                        ->label('სახელი')
                        ->required(fn (Get $get): bool => blank($get('patient_id')))
                        ->visible(fn (Get $get): bool => blank($get('patient_id')))
                        ->maxLength(100),
                    TextInput::make('last_name')
                        ->label('გვარი')
                        ->required(fn (Get $get): bool => blank($get('patient_id')))
                        ->visible(fn (Get $get): bool => blank($get('patient_id')))
                        ->maxLength(100),
                    TextInput::make('birth_date')
                        ->label('დაბადების თარიღი')
                        ->placeholder('04.09.1985')
                        ->helperText('არასავალდებულო · დღე.თვე.წელი')
                        ->visible(fn (Get $get): bool => blank($get('patient_id'))),
                ]),
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->required(),
                    Select::make('currency')->label('ვალუტა')->options(['USD' => '$ USD', 'GEL' => '₾ GEL'])
                        ->default('USD')->native(false)->required(),
                    Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())
                        ->default(PaymentMethod::Cash->value)->native(false)->required(),
                    DatePicker::make('paid_at')->label('გადახდის თარიღი')->default(today())->displayFormat('d.m.Y')->required(),
                ]),
                TextInput::make('notes')->label('შენიშვნა')->maxLength(500)->columnSpanFull(),
            ])
            ->action(function (array $data, IsraeliPatientPaymentService $service): void {
                $service->create($data);
                Notification::make()->success()->title('გადახდა შენახულია.')->send();
            });
    }
}
