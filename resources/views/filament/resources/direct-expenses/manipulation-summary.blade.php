@php
    $items = \App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable::eligibleItems($getRecord());
@endphp

<div x-data="{ expanded: false }" class="max-w-sm space-y-0.5 text-xs leading-5">
    @foreach ($items as $index => $item)
        <div x-show="expanded || {{ $index }} < 2" @if ($index >= 2) x-cloak @endif class="break-words">
            <span class="font-medium text-gray-950 dark:text-white">{{ $item->display_name }}</span>
            <span class="whitespace-nowrap text-[11px] text-gray-500">×{{ $item->quantity }}</span>
        </div>
    @endforeach

    @if ($items->count() > 2)
        <button type="button" class="text-[11px] font-medium text-primary-600 hover:text-primary-700"
            x-on:click.stop="expanded = ! expanded"
            x-text="expanded ? 'დაკეცვა ⌃' : '+ {{ $items->count() - 2 }} სხვა ⌄'">
        </button>
    @endif
</div>
