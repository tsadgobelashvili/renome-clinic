<?php

namespace App\Filament\Resources\TreatmentEstimates\Schemas;

use App\Models\Doctor;
use App\Models\TreatmentEstimate;
use App\Models\TreatmentEstimateItem;
use App\Support\RomanNumeral;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class TreatmentEstimateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                Placeholder::make('progress_planned')->label('დაგეგმილი')
                    ->content(fn (TreatmentEstimate $record): string => self::money($record->getProgressSummary()['planned_amount'])),
                Placeholder::make('progress_executed')->label('შესრულებული')
                    ->content(fn (TreatmentEstimate $record): string => self::money($record->getProgressSummary()['executed_amount'])),
                Placeholder::make('progress_paid')->label('გადახდილი')
                    ->content(fn (TreatmentEstimate $record): string => self::money($record->getProgressSummary()['paid_amount'])),
                Placeholder::make('progress_remaining')->label('დარჩენილი')
                    ->content(fn (TreatmentEstimate $record): string => self::money($record->getProgressSummary()['remaining_amount'])),
            ])->columns(['default' => 2, 'md' => 4])->columnSpanFull()
                ->visible(fn (?TreatmentEstimate $record): bool => $record?->exists === true),
            Select::make('patient_id')->label('პაციენტი')->relationship('patient', 'first_name')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                ->searchable(['first_name', 'last_name', 'phone', 'personal_id'])->preload(false)->required(),
            Select::make('doctor_id')->label('ექიმი')->relationship(
                name: 'doctor', titleAttribute: 'first_name',
                modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
            )->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Doctor::query()
                    ->where('is_active', true)
                    ->searchByName($search)
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])
                    ->all())
                ->preload(false),
            DatePicker::make('estimate_date')->label('თარიღი')->default(now())->required(),

            Repeater::make('options')->relationship()->label('მკურნალობის ვარიანტები')->live()
                ->schema([
                    TextInput::make('name')->label('ვარიანტის სახელი')
                        ->placeholder('მაგ. ეკონომიური / ოპტიმალური')
                        ->default(fn (Get $get): string => 'ვარიანტი '.max(1, count($get('../../options') ?? [])))
                        ->visible(fn (Get $get): bool => count($get('../../options') ?? []) > 1)
                        ->maxLength(255),
                    TextInput::make('estimated_duration')->label('სავარაუდო დრო')
                        ->placeholder('მაგ. 4-6 თვე')->maxLength(255),
                    Repeater::make('stages')->relationship()->hiddenLabel()->live()
                        ->schema([
                            TextInput::make('name')->label('ეტაპის დასახელება')
                                ->placeholder('მაგ. ქირურგიული ეტაპი')
                                ->default(fn (Get $get): string => RomanNumeral::fromInteger(
                                    max(1, count($get('../../stages') ?? [])),
                                ).' ეტაპი')
                                ->visible(fn (Get $get): bool => count($get('../../stages') ?? []) > 1)
                                ->required()->maxLength(255),
                            Repeater::make('items')->relationship()->label('მანიპულაციები')->live()
                                ->schema([
                                    TextInput::make('description')->label('მანიპულაცია')->required()->maxLength(255)
                                        ->datalist(fn (): array => TreatmentEstimateItem::query()->distinct()
                                            ->orderBy('description')->limit(50)->pluck('description')->all())
                                        ->columnSpan(['default' => 1, 'md' => 4]),
                                    TextInput::make('quantity')->label('რაოდენობა')->numeric()->minValue(0.01)
                                        ->step(0.01)->default(1)->required()->live(debounce: 300)
                                        ->columnSpan(['default' => 1, 'md' => 2]),
                                    TextInput::make('unit_price')->label('ერთეულის ფასი')->numeric()->minValue(0)
                                        ->step(0.01)->default(0)->suffix('₾')->required()->live(debounce: 300)
                                        ->columnSpan(['default' => 1, 'md' => 2]),
                                    Placeholder::make('line_total_preview')->label('ჯამი')
                                        ->content(fn (Get $get): string => self::money(
                                            (float) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                                        ))->columnSpan(['default' => 1, 'md' => 2]),
                                ])
                                ->table([
                                    TableColumn::make('მანიპულაცია')->width('45%'),
                                    TableColumn::make('რაოდენობა')->width('15%'),
                                    TableColumn::make('ერთეულის ფასი')->width('20%'),
                                    TableColumn::make('ჯამი')->width('20%'),
                                ])
                                ->addActionLabel('მანიპულაციის დამატება')
                                ->deleteAction(fn (Action $action): Action => $action->tooltip('წაშლა'))
                                ->defaultItems(1)
                                ->reorderable(false)->compact()->columnSpanFull(),
                            Placeholder::make('stage_subtotal_preview')->label('ეტაპის ჯამი')
                                ->content(fn (Get $get): string => self::money(self::itemsSubtotal($get('items') ?? [])))
                                ->columnSpanFull(),
                        ])
                        ->addActionLabel('ეტაპის დამატება')
                        ->deleteAction(fn (Action $action): Action => $action
                            ->tooltip('ეტაპის წაშლა')->requiresConfirmation())
                        ->defaultItems(1)
                        ->orderColumn('sort_order')
                        ->reorderable(fn (Get $get): bool => count($get('stages') ?? []) > 1)
                        ->itemHeaders(fn (Get $get): bool => count($get('stages') ?? []) > 1)
                        ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                            ? $state['name']
                            : null)
                        ->collapsible(fn (Get $get): bool => count($get('stages') ?? []) > 1)
                        ->compact()->columnSpanFull(),
                    Hidden::make('show_discount')->default(false)->dehydrated(false),
                    Actions::make([
                        Action::make('showDiscount')->label('+ ფასდაკლება')->link()
                            ->action(fn (Set $set) => $set('show_discount', true)),
                    ])->visible(fn (Get $get): bool => ! $get('show_discount') && (float) ($get('discount_value') ?? 0) <= 0),
                    Group::make([
                        TextInput::make('discount_value')->label('ფასდაკლება')->numeric()->minValue(0)
                            ->step(0.01)->default(0)->required()->live(debounce: 300)
                            ->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                if ($get('discount_type') === 'percent' && (float) $value > 100) {
                                    $fail('ფასდაკლება 100%-ზე მეტი ვერ იქნება.');
                                }
                                if ($get('discount_type') === 'amount' && (float) $value > self::subtotal($get)) {
                                    $fail('ფასდაკლება ჯამს ვერ გადააჭარბებს.');
                                }
                            }])->columnSpan(3),
                        Select::make('discount_type')->label('ერთეული')->options(['amount' => '₾', 'percent' => '%'])
                            ->default('amount')->required()->native(false)->live()->columnSpan(1),
                        Actions::make([
                            Action::make('removeDiscount')->label('ფასდაკლების გაუქმება')
                                ->icon(Heroicon::XMark)->iconButton()->tooltip('ფასდაკლების გაუქმება')
                                ->action(function (Set $set): void {
                                    $set('discount_value', 0);
                                    $set('discount_type', 'amount');
                                    $set('show_discount', false);
                                }),
                        ])->columnSpan(1),
                    ])->columns(5)->visible(fn (Get $get): bool => (bool) $get('show_discount') || (float) ($get('discount_value') ?? 0) > 0),
                    Group::make([
                        Placeholder::make('option_subtotal_preview')->label('ჯამი')
                            ->content(fn (Get $get): string => self::money(self::subtotal($get)))
                            ->visible(fn (Get $get): bool => self::discount($get) > 0),
                        Placeholder::make('option_discount_preview')->label('ფასდაკლება')
                            ->content(fn (Get $get): string => self::money(self::discount($get)))
                            ->visible(fn (Get $get): bool => self::discount($get) > 0),
                        Placeholder::make('option_final_preview')
                            ->label(fn (Get $get): string => self::discount($get) > 0 ? 'საბოლოო თანხა' : 'ჯამი')
                            ->content(fn (Get $get): string => self::money(self::subtotal($get) - self::discount($get))),
                    ])->columns(['default' => 1, 'md' => 3]),
                ])
                ->addActionLabel('+ ვარიანტი')
                ->defaultItems(1)
                ->itemHeaders(fn (Get $get): bool => count($get('options') ?? []) > 1)
                ->itemLabel(fn (array $state, int $index): string => filled($state['name'] ?? null)
                    ? $state['name']
                    : 'ვარიანტი '.($index + 1))
                ->collapsible(fn (Get $get): bool => count($get('options') ?? []) > 1)
                ->reorderable(false)->columnSpanFull(),
        ]);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2).' ₾';
    }

    private static function subtotal(Get $get): float
    {
        return round(collect($get('stages') ?? [])
            ->sum(fn (array $stage): float => self::itemsSubtotal($stage['items'] ?? [])), 2);
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function itemsSubtotal(array $items): float
    {
        return round(collect($items)
            ->sum(fn (array $item): float => (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0)), 2);
    }

    private static function discount(Get $get): float
    {
        $subtotal = self::subtotal($get);
        $value = (float) ($get('discount_value') ?? 0);
        $discount = $get('discount_type') === 'percent' ? $subtotal * $value / 100 : $value;

        return round(min($subtotal, max(0, $discount)), 2);
    }
}
