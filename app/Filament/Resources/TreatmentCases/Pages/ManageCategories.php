<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use App\Models\TreatmentCase;
use App\Models\TreatmentCategory;
use App\Models\TreatmentStatisticsGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ManageCategories extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TreatmentCaseResource::class;

    protected string $view = 'filament.resources.treatment-cases.uncategorized';

    protected bool $manageGroups = false;

    public function mount(): void
    {
        static::authorizeResourceAccess();
    }

    public function getTitle(): string
    {
        return $this->manageGroups ? 'სტატისტიკის ჯგუფები' : 'კატეგორიები';
    }

    public function table(Table $table): Table
    {
        $fields = [TextInput::make('name')->label('დასახელება')->required()->maxLength(255)];
        if ($this->manageGroups) {
            array_unshift($fields, Select::make('category_id')->label('კატეგორია')->required()->searchable()
                ->options(fn () => TreatmentCase::categoryOptions()));
        }

        return $table->query($this->manageGroups ? TreatmentStatisticsGroup::query()->with('category')->withExists('items') : TreatmentCategory::query()->withExists(['groups', 'items']))
            ->striped()->defaultSort('name')->columns([
                TextColumn::make('name')->label('დასახელება')->searchable()->sortable(),
                TextColumn::make('category.name')->label('კატეგორია')->visible($this->manageGroups),
            ])->headerActions([CreateAction::make()->label('დამატება')->schema($fields)])
            ->recordActions([
                EditAction::make()->iconButton()->schema($fields),
                DeleteAction::make()->iconButton()->requiresConfirmation()
                    ->disabled(fn ($record) => $record instanceof TreatmentCategory
                        ? $record->groups_exists || $record->items_exists
                        : $record->items_exists)
                    ->tooltip('წაშლა შესაძლებელია მხოლოდ გამოუყენებელი ჩანაწერისთვის'),
            ]);
    }
}
