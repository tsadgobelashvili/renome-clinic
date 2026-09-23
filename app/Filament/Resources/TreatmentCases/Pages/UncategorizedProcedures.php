<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\Schemas\TreatmentCaseForm;
use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use App\Models\VisitTreatmentCase;
use App\Services\ProcedureClassification;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UncategorizedProcedures extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TreatmentCaseResource::class;

    protected string $view = 'filament.resources.treatment-cases.uncategorized';

    public function getTitle(): string
    {
        return 'დაუჯგუფებელი პროცედურები';
    }

    public function mount(): void
    {
        static::authorizeResourceAccess();
    }

    public function table(Table $table): Table
    {
        return $table->query(ProcedureClassification::uncategorized(includeMapped: true))
            // Keep the action's record resolvable while it disappears from the list after mapping.
            ->modifyQueryUsing(fn ($query, bool $isResolvingRecord) => $query
                ->when(! $isResolvingRecord, fn ($query) => $query->where('needs_mapping', 1)))
            ->defaultSort('last_used_at', 'desc')->striped()
            ->columns([
                TextColumn::make('procedure_name')->label('პროცედურა')->searchable()->limit(70)->tooltip(fn ($record) => $record->procedure_name),
                TextColumn::make('usage_count')->label('ვიზიტები')->numeric()->sortable(),
                TextColumn::make('charged')->label('თანხა')->alignEnd()->listWithLineBreaks()
                    ->state(fn (VisitTreatmentCase $record): array => ProcedureClassification::financialLines($record, 'charged'))
                    ->tooltip('პროცედურის რაოდენობა × ფასი; ვიზიტის საერთო ფასდაკლებამდე.'),
                TextColumn::make('paid')->label('გადახდილი')->alignEnd()->listWithLineBreaks()
                    ->state(fn (VisitTreatmentCase $record): array => ProcedureClassification::financialLines($record, 'paid'))
                    ->tooltip('გადახდები აღირიცხება ვიზიტზე. „—“: პროცედურაზე ზუსტი განაწილება არ არის ხელმისაწვდომი.'),
                TextColumn::make('last_used_at')->label('ბოლო ვიზიტი')->date('d.m.Y')->sortable(),
                TextColumn::make('status')->label('სტატუსი')->state(fn () => ProcedureClassification::label('uncategorized'))->badge()->color('gray'),
            ])->recordActions([
                Action::make('map')->label('მიკუთვნება')->size('sm')
                    ->schema(TreatmentCaseForm::classificationFields())
                    ->action(function (VisitTreatmentCase $record, array $data): void {
                        static::authorizeResourceAccess();
                        ProcedureClassification::classify($record->procedure_name, $data);
                        Notification::make()->title('შენახულია')->success()->send();
                    }),
            ])->toolbarActions([
                BulkAction::make('map')->label('მიკუთვნება')->size('sm')
                    ->modalSubmitActionLabel('შენახვა')
                    ->modalCancelActionLabel('გაუქმება')
                    ->schema(TreatmentCaseForm::classificationFields())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data, Schema $schema): void {
                        static::authorizeResourceAccess();
                        try {
                            DB::transaction(function () use ($records, $data): void {
                                foreach ($records as $record) {
                                    ProcedureClassification::classify($record->procedure_name, $data);
                                }
                            });
                        } catch (ValidationException $exception) {
                            // Domain errors use model keys; the modal needs its mounted state path.
                            throw ValidationException::withMessages(collect($exception->errors())
                                ->mapWithKeys(fn ($messages, $field) => [$schema->getStatePath().'.'.(in_array($field, ['category', 'statistics_group']) ? $field : 'category') => $messages])
                                ->all());
                        }
                        Notification::make()->title('შენახულია')->success()->send();
                    }),
            ]);
    }
}
