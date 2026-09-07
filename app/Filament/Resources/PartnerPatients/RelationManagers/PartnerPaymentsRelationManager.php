<?php

namespace App\Filament\Resources\PartnerPatients\RelationManagers;

use App\Enums\PaymentMethod;
use App\Support\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartnerPaymentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'partnerPayments';

    protected static ?string $title = 'გადახდები';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label('თანხა')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->suffix(fn (Get $get): string => Currency::symbol($get('currency') ?: Currency::DEFAULT))
                    ->required(),
                Select::make('currency')
                    ->label('ვალუტა')
                    ->options(Currency::OPTIONS)
                    ->default(Currency::DEFAULT)
                    ->live()
                    ->native(false)
                    ->required(),
                Select::make('payment_method')
                    ->label('გადახდის მეთოდი')
                    ->options(PaymentMethod::options())
                    ->default(PaymentMethod::Cash->value)
                    ->native(false)
                    ->required(),
                DatePicker::make('paid_at')
                    ->label('თარიღი')
                    ->default(today())
                    ->displayFormat('d.m.Y')
                    ->required(),
                Textarea::make('notes')
                    ->label('შენიშვნა')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->header(fn () => view('filament.resources.partner-patients.payment-header', ['totals' => $this->getOwnerRecord()->getPartnerPaymentTotals()]))
            ->striped()
            ->extraAttributes(['class' => 'renome-partner-payment-history'])
            ->emptyState(view('filament.resources.partner-patients.empty-payments'))
            ->columns([
                TextColumn::make('paid_at')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('amount')
                    ->label('თანხა')
                    ->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, $record): string => Currency::format($state, $record->currency)),
                TextColumn::make('currency')->label('ვალუტა')->badge(),
                TextColumn::make('payment_method_label')->label('გადახდის მეთოდი'),
                TextColumn::make('notes')->label('შენიშვნა')->placeholder('—')->wrap(),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('paid_at')->orderByDesc('id'))
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10);
    }
}
