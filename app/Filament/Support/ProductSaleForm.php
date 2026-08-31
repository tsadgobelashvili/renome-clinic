<?php

namespace App\Filament\Support;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\Product;
use App\Services\NbgExchangeRate;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

final class ProductSaleForm
{
    /** @return array<int, mixed> */
    public static function schema(bool $includePatient = true, bool $includeDate = true, bool $compact = false): array
    {
        return [
            ...($includeDate ? [DateTimePicker::make('sold_at')->label('თარიღი / დრო')->timezone(config('app.timezone'))->default(now())->required()] : []),
            Repeater::make('items')->label('პროდუქტები')->schema([
                Select::make('product_id')->label('პროდუქტი')->options(fn (): array => Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required()->live()
                    ->createOptionForm([
                        TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                        TextInput::make('selling_price')->label('გასაყიდი ფასი')->numeric()->minValue(0.01)->required()->suffix('₾'),
                    ])->createOptionUsing(fn (array $data): int => Product::create([...$data, 'is_active' => true])->getKey())
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $set('unit_price', Product::query()->find($state)?->selling_price);
                        self::syncPaymentAmount($get, $set, '../../');
                    }),
                TextInput::make('quantity')->label('რაოდ.')->numeric()->integer()->minValue(1)->default(1)
                    ->afterStateHydrated(function (mixed $state, Set $set): void {
                        if (blank($state)) {
                            $set('quantity', 1);
                        }
                    })->required()->live(debounce: 250)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentAmount($get, $set, '../../')),
                TextInput::make('unit_price')->label('ფასი')->numeric()->minValue(0.01)->step(0.01)->suffix('₾')->required()->live(debounce: 250)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentAmount($get, $set, '../../')),
                Placeholder::make('line_total')->label('ჯამი')->content(fn (Get $get): string => Currency::format(
                    max((int) ($get('quantity') ?? 1), 1) * (float) ($get('unit_price') ?? 0),
                )),
            ])->table([
                TableColumn::make('პროდუქტი')->width('48%'), TableColumn::make('რაოდ.')->width('12%'),
                TableColumn::make('ფასი')->width('18%'), TableColumn::make('ჯამი')->width('18%'), TableColumn::make('')->width('4%'),
            ])->defaultItems(1)->minItems(1)->reorderable(false)->compact()
                ->addAction(fn (Action $action): Action => $action
                    ->label($compact ? '+ პროდუქტი' : '+ პროდუქტის დამატება')
                    ->link()
                    ->size('sm'))
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncPaymentAmount($get, $set)),
            ...($compact ? [] : [self::saleTotal()]),
            ...($includePatient ? [Select::make('patient_id')->label('პაციენტი (არასავალდებულო)')
                ->options(fn (): array => Patient::query()->orderBy('first_name')->limit(100)->get()->mapWithKeys(fn (Patient $patient): array => [$patient->getKey() => $patient->full_name])->all())
                ->searchable()] : []),
            Hidden::make('currency')->default(Currency::DEFAULT)->live(),
            Hidden::make('exchange_rate'),
            ...($compact ? [
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    self::paymentMethod(),
                    self::paymentAmount(),
                    self::saleTotal(),
                ]),
            ] : [
                self::paymentMethod(),
                self::paymentAmount(),
                self::exchangeRatePreview(),
            ]),
            Textarea::make('note')->label('კომენტარი')->rows($compact ? 1 : 2),
        ];
    }

    private static function saleTotal(): Placeholder
    {
        return Placeholder::make('sale_total')->label('სულ')->content(fn (Get $get): string => Currency::format(
            collect($get('items') ?? [])->sum(fn (array $item): float => max((int) ($item['quantity'] ?? 1), 1) * (float) ($item['unit_price'] ?? 0)),
        ));
    }

    private static function paymentMethod(): Select
    {
        return Select::make('payment_method')->label('გადახდის მეთოდი')->options(PaymentMethod::options())->default('cash')->required();
    }

    private static function paymentAmount(): TextInput
    {
        return TextInput::make('payment_amount')->label('გადასახდელი თანხა')->numeric()->readOnly()
            ->suffixAction(self::currencyToggleAction());
    }

    private static function exchangeRatePreview(): Placeholder
    {
        return Placeholder::make('exchange_rate_preview')->label('NBG USD/GEL კურსი')
            ->content(fn (Get $get): string => filled($get('exchange_rate')) ? number_format((float) $get('exchange_rate'), 4) : '—')
            ->visible(fn (Get $get): bool => $get('currency') === 'USD');
    }

    private static function currencyToggleAction(): Action
    {
        return Action::make('toggleProductSaleCurrency')
            ->label(fn (Get $get): string => Currency::symbol($get('currency')))
            ->tooltip('₾ / $')
            ->link()
            ->color('gray')
            ->extraAttributes(['class' => 'min-w-8 justify-center font-semibold text-gray-700 dark:text-gray-200'])
            ->action(function (Get $get, Set $set): void {
                $currency = ($get('currency') ?: Currency::DEFAULT) === Currency::DEFAULT ? 'USD' : Currency::DEFAULT;
                $set('currency', $currency);

                if ($currency === Currency::DEFAULT) {
                    $set('exchange_rate', null);
                    self::syncPaymentAmount($get, $set);

                    return;
                }

                try {
                    $set('exchange_rate', app(NbgExchangeRate::class)->usdGel());
                    self::syncPaymentAmount($get, $set);
                } catch (Throwable $exception) {
                    report($exception);
                    $set('exchange_rate', null);
                    $set('payment_amount', null);
                    Notification::make()->warning()->title('NBG-ის კურსი ვერ ჩაიტვირთა')->send();
                }
            });
    }

    private static function syncPaymentAmount(Get $get, Set $set, string $root = ''): void
    {
        $total = (float) collect($get($root.'items') ?? [])->sum(
            fn (array $item): float => max((int) ($item['quantity'] ?? 1), 1) * (float) ($item['unit_price'] ?? 0),
        );
        $currency = $get($root.'currency') ?: Currency::DEFAULT;
        $rate = (float) ($get($root.'exchange_rate') ?? 0);
        $amount = $currency === Currency::DEFAULT ? $total : ($rate > 0 ? $total / $rate : 0);

        $set($root.'payment_amount', Money::decimal($amount));
    }
}
