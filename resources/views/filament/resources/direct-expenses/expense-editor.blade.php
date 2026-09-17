@php
    $record = $getRecord();
    $items = \App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable::eligibleItems($record);
    $currency = $record->currency ?: \App\Support\Currency::DEFAULT;
@endphp
<div class="space-y-2" wire:key="expense-editor-{{ $record->getKey() }}">
    @foreach($items as $item)
        <div class="rounded-lg border border-gray-200 p-2 text-xs dark:border-white/10">
            <div class="font-medium">{{ $item->display_name }}</div>
            @foreach($item->directExpenses->where('currency', $currency) as $expense)
                <div class="flex items-center gap-2 py-1">
                    <span class="grow">{{ $expense->name }}
                        @if($expense->expense_direction_id || $expense->expense_type_id)
                            <span class="block text-gray-500">{{ app(\App\Services\ExpenseDimensions::class)->summary($expense) }}</span>
                        @elseif($expense->expense_category_id)
                            <span class="block text-gray-500">{{ $expense->expenseCategory?->name }} @if($expense->expenseSubcategory) / {{ $expense->expenseSubcategory->name }} @endif</span>
                        @endif
                    </span>
                    <span>{{ \App\Support\Currency::format($expense->amount, $currency) }}</span>
                    <x-filament::button size="xs" color="gray" wire:click="mountAction('expense', { item: {{ $item->id }}, expense: {{ $expense->id }} })">{{ __('expense-categories.edit') }}</x-filament::button>
                    <x-filament::button size="xs" color="danger" wire:click="deleteExpense({{ $item->id }}, {{ $expense->id }})">?</x-filament::button>
                </div>
            @endforeach
            <x-filament::button size="xs" color="gray" wire:click="mountAction('expense', { item: {{ $item->id }} })">+</x-filament::button>
        </div>
    @endforeach
    <div class="border-t border-gray-200 pt-2 text-right text-xs font-semibold dark:border-white/10">
        {{ \App\Support\Currency::format(\App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable::visitExpenseTotal($record), $currency) }}
    </div>
</div>
