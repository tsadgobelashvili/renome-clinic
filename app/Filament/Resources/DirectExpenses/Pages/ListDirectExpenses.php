<?php

namespace App\Filament\Resources\DirectExpenses\Pages;

use App\Filament\Resources\DirectExpenses\DirectExpenseResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectExpenses extends ListRecords
{
    protected static string $resource = DirectExpenseResource::class;
}
