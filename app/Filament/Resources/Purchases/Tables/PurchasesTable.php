<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Models\Purchase;
use App\Services\PurchaseCatalog;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->extraAttributes(['class' => 'renome-rs-documents'])->columns([
            TextColumn::make('purchase_date')->label('თარიღი')->date('d.m.Y')->sortable()->width('7rem'),
            TextColumn::make('supplier.name')->label('მომწოდებელი')->searchable()->sortable()->limit(35)->tooltip(fn (Purchase $record) => $record->supplier?->name),
            TextColumn::make('items_count')->label('რაოდ.')->alignEnd()->width('4rem'),
            TextColumn::make('classification')->label('კლასიფიკაცია')->badge()
                ->state(fn (Purchase $record) => $record->unclassified_count > 0 ? 'უკატეგორიო: '.$record->unclassified_count : 'მინიჭებულია')
                ->color(fn (Purchase $record) => $record->unclassified_count > 0 ? 'gray' : 'success'),
            TextColumn::make('document_number')->label('დოკუმენტი №')->placeholder('—')->searchable()->limit(20)->tooltip(fn (Purchase $record) => $record->document_number)->width('9rem'),
            TextColumn::make('source')->label('წყარო')->badge()->placeholder('Manual')->width('4rem'),
            TextColumn::make('total_amount')->label('ჯამი')->money('GEL')->alignEnd()->sortable()->width('8rem'),
        ])->filters([
            Filter::make('date')->schema([
                DatePicker::make('from')->label('დან'), DatePicker::make('until')->label('მდე'),
            ])->query(fn ($query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('purchase_date', '>=', $date))
                ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('purchase_date', '<=', $date))),
            SelectFilter::make('supplier_id')->label('მომწოდებელი')->relationship('supplier', 'name')->searchable()->preload(),
        ])->recordActions([
            Action::make('breakdown')->label('ანალიზი')->icon('heroicon-o-chart-pie')->iconButton()->tooltip('მიმართულებების ანალიზი')->modalHeading('მიმართულებების ანალიზი')->modalWidth('md')
                ->modalContent(fn (Purchase $record) => view('filament.resources.purchases.breakdown', ['rows' => app(PurchaseCatalog::class)->breakdown($record->id)]))
                ->modalSubmitAction(false)->modalCancelActionLabel('დახურვა'),
            EditAction::make()->label('რედაქტირება')->iconButton()->tooltip('რედაქტირება'),
        ])->defaultSort('purchase_date', 'desc');
    }
}
