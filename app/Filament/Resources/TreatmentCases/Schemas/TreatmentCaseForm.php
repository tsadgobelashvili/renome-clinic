<?php

namespace App\Filament\Resources\TreatmentCases\Schemas;

use App\Models\TreatmentCase;
use App\Models\TreatmentStatisticsGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class TreatmentCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('დასახელება')
                    ->maxLength(255)
                    ->required(),

                ...self::classificationFields(),

                TextInput::make('default_price')
                    ->label('ფასი')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('₾'),

                Toggle::make('is_active')
                    ->label('აქტიურია')
                    ->default(true),
            ]);
    }

    /** Shared by catalog editing and the uncategorized-procedure assignment modal. */
    public static function classificationFields(): array
    {
        return [
            Select::make('category')->label('კატეგორია')->options(fn () => TreatmentCase::categoryOptions())
                ->getSearchResultsUsing(fn (string $search) => self::searchOptions(TreatmentCase::categoryOptions(), $search))
                ->searchable()->native(false)->required()->live()
                ->validationMessages(['required' => 'კატეგორია სავალდებულოა'])
                ->afterStateUpdated(fn (Set $set) => $set('statistics_group', null)),
            Radio::make('statistics_group_mode')->label('სტატისტიკაში განთავსება')
                ->options(['group' => 'ჯგუფში', 'direct' => 'პირდაპირ კატეგორიაში'])
                ->default('group')->inline()->live()->required()->in(['group', 'direct'])->dehydrated(false)
                ->afterStateHydrated(fn (Radio $component, $record) => $component->state($record instanceof TreatmentCase && $record->statistics_group === null ? 'direct' : 'group'))
                ->afterStateUpdated(function ($state, Set $set) {
                    if ($state === 'direct') {
                        $set('statistics_group', null);
                    }
                }),
            Select::make('statistics_group')->label('სტატისტიკის ჯგუფი')
                ->key(fn (Get $get) => 'classification-group-'.($get('category') ?? 'none').'-'.$get('statistics_group_mode'))
                ->options(fn (Get $get) => filled($get('category')) ? TreatmentCase::statisticsGroupOptions($get('category')) : [])
                ->getSearchResultsUsing(fn (string $search, Get $get) => filled($get('category')) ? self::searchOptions(TreatmentCase::statisticsGroupOptions($get('category')), $search) : [])
                ->searchable()->native(false)->live()
                ->afterStateUpdated(fn (Select $component, $livewire) => $livewire->validateOnly($component->getStatePath()))
                ->visible(fn (Get $get) => $get('statistics_group_mode') === 'group')
                ->required(fn (Get $get) => $get('statistics_group_mode') === 'group')
                ->dehydratedWhenHidden()
                ->mutateStateForValidationUsing(fn ($state, Get $get) => $get('statistics_group_mode') === 'group' ? $state : null)
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('statistics_group_mode') === 'group' ? $state : null)
                ->validationMessages(['required' => 'სტატისტიკის ჯგუფი სავალდებულოა', 'in' => 'აირჩიეთ ამ კატეგორიის ჯგუფი'])
                ->createOptionForm([TextInput::make('name')->label('ჯგუფის სახელი')->required()->maxLength(255)])
                ->createOptionAction(fn ($action) => $action->label('+ ჯგუფის დამატება')->modalHeading('ჯგუფის დამატება')
                    ->visible(fn (Get $get) => filled($get('category'))))
                ->createOptionUsing(function (array $data, Get $get): string {
                    Gate::authorize('create', TreatmentStatisticsGroup::class);

                    return TreatmentStatisticsGroup::create(['name' => $data['name'], 'category_id' => $get('category')])->id;
                }),
        ];
    }

    private static function searchOptions(array $options, string $search): array
    {
        $search = mb_strtolower(trim($search));

        return array_filter($options, fn (string $label) => str_contains(mb_strtolower($label), $search));
    }
}
