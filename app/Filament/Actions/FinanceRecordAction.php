<?php

namespace App\Filament\Actions;

use App\Enums\PartnerAccount;
use App\Enums\PaymentMethod;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Payment;
use App\Services\Finance\OriginalRecordEditor;
use App\Services\FinanceUsdUsageService;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

final class FinanceRecordAction
{
    public static function make(bool $israeliOnly = false): Action
    {
        $editor = app(OriginalRecordEditor::class);
        $resolve = function (array $arguments, ?Model $record = null) use ($editor, $israeliOnly): Model {
            if ($israeliOnly) {
                abort_unless($record instanceof PartnerFinanceEntry, 404);
                $key = ($record->source_type === 'payment' ? 'partner-payment:' : 'partner:').$record->source_id;
            } else {
                $key = (string) ($arguments['key'] ?? '');
            }

            return $editor->find($key, $israeliOnly);
        };

        return Action::make('financeRecord')->label('დეტალები / რედაქტირება')
            ->visible(fn (): bool => auth()->user()?->canManageOwnerModules() ?? false)
            ->modalHeading('ტრანზაქციის სრული დეტალები')->modalWidth('3xl')
            ->modalSubmitAction(false)->modalCancelActionLabel('დახურვა')
            ->modalContent(function (array $arguments, ?Model $record = null) use ($resolve, $editor) {
                $record = $resolve($arguments, $record);

                return view('filament.pages.finance-record-details', ['record' => $record, 'reason' => $editor->reason($record)]);
            })
            ->extraModalFooterActions(function (array $arguments, ?Model $record = null) use ($resolve, $editor, $israeliOnly): array {
                $record = $resolve($arguments, $record);
                if ($editor->reason($record)) {
                    return [];
                }

                $key = match (true) {
                    $record instanceof Payment => 'payment:', $record instanceof PartnerPatientPayment => 'partner-payment:',
                    $record instanceof FinanceTransaction => 'finance:', default => 'partner:',
                }.$record->getKey();

                return [Action::make('editOriginal')->label('რედაქტირება')->modalHeading('ტრანზაქციის რედაქტირება')
                    ->modalSubmitActionLabel('შენახვა')->schema(self::schema($record))
                    ->fillForm(function () use ($record): array {
                        $data = $record->attributesToArray();
                        if ($record instanceof Payment) {
                            $data['splits'] = $record->splits()->orderBy('id')->get()->map->only(['payment_method', 'amount', 'currency', 'exchange_rate'])->all();
                        }

                        return $data;
                    })
                    ->action(function (array $data) use ($key, $editor, $israeliOnly): void {
                        $editor->update($key, $data, $israeliOnly);
                        Notification::make()->success()->title('ტრანზაქცია განახლდა.')->send();
                    })->cancelParentActions()];
            });
    }

    private static function schema(Model $record): array
    {
        $amount = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(0.01)->step(0.01)->required();
        $currency = fn (string $name) => Select::make($name)->label('ვალუტა')->options(Currency::OPTIONS)->required();
        if ($record instanceof Payment) {
            return [DateTimePicker::make('payment_date')->label('გადახდის თარიღი')->required(),
                Repeater::make('splits')->label('გადახდის განაწილება')->minItems(1)->reorderable(false)->schema([
                    Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())->required(),
                    $currency('currency')->live(), $amount('amount', 'თანხა'),
                    TextInput::make('exchange_rate')->label('კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                        ->visible(fn (Get $get): bool => $get('currency') !== $record->currency)->required(fn (Get $get): bool => $get('currency') !== $record->currency),
                ])->columns(2), Textarea::make('comment')->label('შენიშვნა')];
        }
        $date = $record instanceof FinanceTransaction ? 'transaction_date' : ($record instanceof PartnerPatientPayment ? 'paid_at' : 'transacted_at');
        $fields = [DateTimePicker::make($date)->label('თარიღი / დრო')->required()];
        if ($record instanceof PartnerFinanceTransaction && $record->type === 'currency_exchange') {
            $usdGel = $record->from_currency === 'USD' && $record->to_currency === 'GEL';
            $sync = function (Get $get, Set $set) use ($usdGel): void {
                if ($usdGel) {
                    $set('to_amount', FinanceUsdUsageService::calculateReceivedGel($get('from_amount'), $get('exchange_rate')));
                }
            };
            $fields = [...$fields, $amount('from_amount', 'გასაცემი თანხა')->live()->afterStateUpdated($sync), $currency('from_currency')->disabled(),
                $amount('to_amount', 'მისაღები თანხა')->readOnly($usdGel), $currency('to_currency')->disabled(),
                TextInput::make('exchange_rate')->label('კურსი')->numeric()->minValue(0.000001)->step(0.000001)->required()->live()->afterStateUpdated($sync)];
        } else {
            $fields = [...$fields, $amount('amount', 'თანხა'), $currency('currency')];
        }
        if ($record instanceof PartnerFinanceTransaction) {
            $fields[] = Select::make('from_account')->label('ანგარიშიდან')->options(PartnerAccount::options())->required();
            if (in_array($record->type, ['transfer', 'currency_exchange'], true)) {
                $fields[] = Select::make('to_account')->label('ანგარიშზე')->options(PartnerAccount::options())->required();
            }
            $fields[] = TextInput::make('recipient')->label('მიმღები')->maxLength(255);
        } else {
            $fields[] = Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())->required()->live();
        }
        if ($record instanceof FinanceTransaction) {
            $fields[] = Select::make('cash_source')->label('ნაღდი თანხის წყარო')
                ->options(array_diff_key(FinanceTransaction::CASH_SOURCES, ['israeli' => true]))
                ->visible(fn (Get $get): bool => $get('payment_method') === 'cash')->required(fn (Get $get): bool => $get('payment_method') === 'cash');
            $fields[] = TextInput::make('description')->label('აღწერა / წყარო')->maxLength(255);
        }
        if ($record->type === 'expense') {
            $fields = [...$fields, ...ExpenseCategoryForm::schema()];
        }
        $fields[] = Textarea::make($record instanceof FinanceTransaction ? 'note' : 'notes')->label('შენიშვნა')->rows(3);

        return $fields;
    }
}
