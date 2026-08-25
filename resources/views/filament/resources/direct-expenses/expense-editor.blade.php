@php
    $record = $getRecord();
    $items = \App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable::eligibleItems($record);
    $currency = $record->currency ?: \App\Support\Currency::DEFAULT;
    $symbol = \App\Support\Currency::symbol($currency);
    $total = \App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable::visitExpenseTotal($record);
    $sourceListId = 'expense-sources-' . $record->getKey();
@endphp

<div class="min-w-[20rem] max-w-[26rem] space-y-2" wire:key="expense-editor-{{ $record->getKey() }}">
    <datalist id="{{ $sourceListId }}">
        <option value="ლაბ"></option>
        <option value="ტექნიკი"></option>
        <option value="მომწოდებელი"></option>
        <option value="სხვა"></option>
    </datalist>

    @foreach ($items as $item)
        <div class="space-y-1 rounded-lg border border-gray-200 p-1.5 dark:border-white/10">
            <div class="truncate text-[11px] font-medium text-gray-500" title="{{ $item->display_name }}">{{ $item->display_name }}</div>

            @foreach ($item->directExpenses->where('currency', $currency) as $expense)
                <div class="grid grid-cols-[minmax(0,1fr)_7.25rem_1.75rem] items-center gap-1"
                    x-data="{
                        name: @js($expense->name),
                        amount: @js(number_format((float) $expense->amount, 2, '.', '')),
                        savedName: @js($expense->name),
                        savedAmount: @js(number_format((float) $expense->amount, 2, '.', '')),
                        save() {
                            $wire.saveExpense({{ $item->getKey() }}, {{ $expense->getKey() }}, this.name, this.amount)
                                .then((saved) => {
                                    if (saved) {
                                        this.savedName = this.name
                                        this.savedAmount = this.amount
                                    } else {
                                        this.name = this.savedName
                                        this.amount = this.savedAmount
                                    }
                                })
                        },
                    }">
                    <input type="text" list="{{ $sourceListId }}" x-model="name" placeholder="წყარო / დასახელება"
                        class="min-w-0 rounded-md border-gray-300 px-2 py-1 text-xs dark:border-white/10 dark:bg-gray-900"
                        x-on:keydown.enter.prevent="save()"
                        x-on:change="save()" />
                    <div class="flex min-w-0 items-center overflow-hidden rounded-md border border-gray-300 bg-white dark:border-white/10 dark:bg-gray-900">
                        <input type="number" min="0.01" step="0.01" x-model="amount"
                            class="min-w-0 flex-1 border-0 bg-transparent px-2 py-1 text-right text-xs focus:ring-0"
                            x-on:keydown.enter.prevent="save()"
                            x-on:change="save()" />
                        <span class="border-s border-gray-200 px-1.5 text-xs font-medium text-gray-500 dark:border-white/10">{{ $symbol }}</span>
                    </div>
                    <button type="button" class="flex size-7 items-center justify-center rounded-md text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950"
                        title="წაშლა" wire:click="deleteExpense({{ $item->getKey() }}, {{ $expense->getKey() }})">×</button>
                </div>
            @endforeach

            <div x-data="{ open: false, name: '', amount: '' }">
                <button x-show="! open" type="button" class="text-[11px] font-medium text-primary-600 hover:text-primary-700"
                    x-on:click="open = true">+ ხარჯის დამატება</button>
                <div x-show="open" x-cloak class="grid grid-cols-[minmax(0,1fr)_7.25rem_3.75rem] items-center gap-1">
                    <input type="text" list="{{ $sourceListId }}" x-model="name" placeholder="წყარო / დასახელება"
                        class="min-w-0 rounded-md border-gray-300 px-2 py-1 text-xs dark:border-white/10 dark:bg-gray-900" />
                    <div class="flex min-w-0 items-center overflow-hidden rounded-md border border-gray-300 bg-white dark:border-white/10 dark:bg-gray-900">
                        <input type="number" min="0.01" step="0.01" x-model="amount" placeholder="0.00"
                            class="min-w-0 flex-1 border-0 bg-transparent px-2 py-1 text-right text-xs focus:ring-0" />
                        <span class="border-s border-gray-200 px-1.5 text-xs font-medium text-gray-500 dark:border-white/10">{{ $symbol }}</span>
                    </div>
                    <div class="flex gap-0.5">
                        <button type="button" title="შენახვა" class="size-7 rounded-md text-primary-600 hover:bg-primary-50"
                            x-on:click="$wire.saveExpense({{ $item->getKey() }}, null, name, amount).then((saved) => { if (saved) { open = false; name = ''; amount = '' } })">✓</button>
                        <button type="button" title="გაუქმება" class="size-7 rounded-md text-gray-500 hover:bg-gray-100"
                            x-on:click="open = false; name = ''; amount = ''">×</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="flex items-center justify-between border-t border-gray-200 pt-1.5 text-xs dark:border-white/10">
        <span class="font-medium text-gray-500">სულ</span>
        <span class="whitespace-nowrap font-semibold text-gray-950 dark:text-white">{{ \App\Support\Currency::format($total, $currency) }}</span>
    </div>
</div>
