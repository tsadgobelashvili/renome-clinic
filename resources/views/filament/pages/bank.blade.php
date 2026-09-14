<x-filament-panels::page>
    <div class="space-y-3">
        <div class="grid gap-2 sm:grid-cols-3">
            <section class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-400/20 dark:bg-indigo-400/5">
                <h2 class="text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('bank.balance') }}</h2>
                @foreach($currency ? [$currency] : $currencies as $balanceCurrency)
                    @forelse($balances->where('currency', $balanceCurrency) as $balance)
                        <div class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($balance->reported_balance, 2) }} {{ $balance->currency }}</div>
                        <div class="break-all text-xs text-gray-500">{{ $balance->account_identifier }}</div>
                        @include('filament.pages.bank-balance-updated')
                    @empty
                        <div class="mt-1 text-lg font-semibold">{{ $balanceCurrency }} —</div>
                        <div class="text-xs text-gray-500">{{ __('bank.unknown_balance') }}</div>
                    @endforelse
                @endforeach
            </section>
            @foreach(['expenses', 'fees'] as $metric)
                <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-xs font-medium text-gray-500">{{ __('bank.'.$metric) }}</h2>
                    @forelse($totals as $total)
                        <div class="mt-1 text-lg font-semibold tabular-nums {{ (float) $total->$metric === 0.0 ? 'text-gray-400' : ($metric === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') }}">{{ number_format($total->$metric, 2) }} {{ $total->currency }}</div>
                    @empty
                        <div class="mt-1 text-lg font-semibold text-gray-400">0.00 {{ $currency ?: 'GEL' }}</div>
                    @endforelse
                    <p class="mt-1 text-xs text-gray-500">{{ __('bank.filtered_period') }}</p>
                </section>
            @endforeach
        </div>

        <section class="renome-visits-toolbar flex-wrap" aria-label="{{ __('bank.filters') }}">
            <label class="renome-visits-toolbar__doctor"><select wire:model.live="viewMode" aria-label="{{ __('bank.visibility') }}"><option value="relevant">{{ __('bank.relevant') }}</option><option value="all">{{ __('bank.all_transactions') }}</option></select></label>
            <div class="renome-visits-toolbar__period">
                <label class="renome-visits-toolbar__date"><span class="fi-sr-only">{{ __('bank.from') }}</span><input type="date" wire:model.live="dateFrom" aria-label="{{ __('bank.from') }}"></label>
                <span aria-hidden="true">—</span>
                <label class="renome-visits-toolbar__date"><span class="fi-sr-only">{{ __('bank.to') }}</span><input type="date" wire:model.live="dateUntil" aria-label="{{ __('bank.to') }}"></label>
            </div>
            <label class="renome-visits-toolbar__doctor"><span class="fi-sr-only">{{ __('bank.period') }}</span>
                <select wire:model.live="period" aria-label="{{ __('bank.period') }}">@foreach(__('bank.periods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="renome-visits-toolbar__doctor"><span class="fi-sr-only">{{ __('bank.direction') }}</span>
                <select wire:model.live="direction" aria-label="{{ __('bank.direction') }}"><option value="">{{ __('bank.all_directions') }}</option><option value="inflow">{{ __('bank.inflow') }}</option><option value="outflow">{{ __('bank.outflow') }}</option></select>
            </label>
            <label class="renome-visits-toolbar__doctor"><span class="fi-sr-only">{{ __('bank.currency') }}</span>
                <select wire:model.live="currency" aria-label="{{ __('bank.currency') }}"><option value="">{{ __('bank.all_currencies') }}</option>@foreach($currencies as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach</select>
            </label>
            <label class="renome-visits-toolbar__doctor"><span class="fi-sr-only">{{ __('bank.category') }}</span>
                <select wire:model.live="category" aria-label="{{ __('bank.category') }}"><option value="">{{ __('bank.all_categories') }}</option>@foreach($expenseCategories as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>
            </label>
            <label class="renome-visits-toolbar__doctor"><span class="fi-sr-only">{{ __('bank.operation_type') }}</span>
                <select wire:model.live="operationType" aria-label="{{ __('bank.operation_type') }}"><option value="">{{ __('bank.all_types') }}</option>@foreach($operationTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
            </label>
            <label class="renome-visits-toolbar__search"><span class="fi-sr-only">{{ __('bank.search') }}</span><input type="search" wire:model.live.debounce.400ms="search" maxlength="255" placeholder="{{ __('bank.search') }}"></label>
        </section>
        @if($dateError)<p role="alert" class="text-sm text-rose-600">{{ $dateError }}</p>@endif
        @error('category')<p role="alert" class="text-sm text-rose-600">{{ $message }}</p>@enderror

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
                    @foreach(['date', 'direction', 'amount', 'counterparty', 'description', 'category'] as $column)
                        <th scope="col" class="px-3 py-2 font-medium {{ $column === 'amount' ? 'text-right' : '' }}">{{ __($column === 'pnl_status' ? 'bank-accounting.treatment' : 'bank.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($transactions as $transaction)
                        <tr wire:key="bank-transaction-{{ $transaction->id }}" class="even:bg-gray-50/70 dark:even:bg-white/5">
                            <td class="whitespace-nowrap px-3 py-2"><button type="button" wire:click="showTransaction({{ $transaction->id }})" class="text-primary-600 hover:underline" aria-label="{{ __('bank.details') }} {{ $transaction->transaction_date->format('d.m.Y') }}">{{ $transaction->transaction_date->format('d.m.Y') }}</button></td>
                            <td class="px-3 py-2 text-xs {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ __('bank.'.$transaction->direction) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ number_format($transaction->amount, 2) }} <span class="text-xs">{{ $transaction->currency }}</span></td>
                            <td class="max-w-48 px-3 py-2">{{ $transaction->counterparty_name ?: '—' }}</td>
                            <td class="max-w-64 px-3 py-2"><button type="button" class="text-left hover:underline" wire:click="showTransaction({{ $transaction->id }})">{{ \Illuminate\Support\Str::limit($transaction->description ?: __('bank.details'), 90) }}</button></td>
                            <td class="px-3 py-2 text-xs"><button type="button" class="text-left text-primary-600 hover:underline" wire:click="showTransaction({{ $transaction->id }})">{{ $expenseCategories->firstWhere('id', $transaction->expense_category_id)?->name ?? ($categories->firstWhere('id', $transaction->bank_category_id)?->accounting_treatment === 'expense' ? $expenseCategories->firstWhere('id', $categories->firstWhere('id', $transaction->bank_category_id)?->expense_category_id)?->name : $categories->firstWhere('id', $transaction->bank_category_id)?->name) ?? __('bank-rules.uncategorized') }}@if($transaction->expense_subcategory_id)<span class="block text-gray-500">{{ $expenseSubcategories->firstWhere('id', $transaction->expense_subcategory_id)?->name }}</span>@endif</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}

        @if($transactionDetail)
            <section wire:key="bank-detail-{{ $transactionDetail->id }}" class="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                <div class="flex items-center justify-between"><h2 class="font-semibold">{{ __('bank.details') }} #{{ $transactionDetail->id }}</h2><x-filament::button size="xs" color="gray" wire:click="showTransaction(null)">{{ __('bank.close') }}</x-filament::button></div>
                <p class="whitespace-pre-wrap text-sm">{{ $transactionDetail->description }}</p>
                @if($transactionDetail->direction === 'outflow')
                    <form wire:submit="saveExpenseClassification" class="space-y-2">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="text-xs">{{ __('expense-categories.category') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="expenseCategoryId"><option value="">{{ __('bank-rules.uncategorized') }}</option>@foreach($expenseCategories as $option)@if($option->active || $transactionDetail->expense_category_id === $option->id)<option value="{{ $option->id }}">{{ $option->name }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                            <label class="text-xs">{{ __('expense-categories.subcategory') }}<x-filament::input.wrapper><x-filament::input.select wire:model="expenseSubcategoryId" :disabled="!$expenseCategoryId"><option value="">—</option>@foreach($expenseSubcategories->where('expense_category_id', $expenseCategoryId) as $option)@if($option->active || $transactionDetail->expense_subcategory_id === $option->id)<option value="{{ $option->id }}">{{ $option->name }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        </div>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="rememberRule">{{ __('bank-rules.remember') }}</label>
                        @if($transactionDetail->categorization_rule_id)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="updateSavedRule">{{ __('bank-rules.update_rule') }}</label>@endif
                        @if($rememberRule || $updateSavedRule)
                            <div class="space-y-2 rounded-lg bg-gray-50 p-2 text-xs dark:bg-white/5">
                                <p>{{ __('bank-rules.counterparty') }}: <strong>{{ $transactionDetail->counterparty_name ?: '—' }}</strong> → {{ $expenseCategories->firstWhere('id', $expenseCategoryId)?->name ?: __('bank-rules.uncategorized') }} @if($expenseSubcategoryId) / {{ $expenseSubcategories->firstWhere('id', $expenseSubcategoryId)?->name }} @endif</p>
                                <label class="block">{{ __('bank-rules.keyword') }}<x-filament::input.wrapper><x-filament::input wire:model="ruleKeyword" maxlength="255" /></x-filament::input.wrapper></label>
                                @if($transactionDetail->counterparty_account)<label class="flex items-center gap-2"><input type="checkbox" wire:model="useCounterpartyAccount">{{ __('bank-rules.use_account') }} · {{ $transactionDetail->counterparty_account }}</label>@endif
                                <label class="flex items-start gap-2"><input type="checkbox" wire:model="confirmCompanyDefault">{{ __('bank-rules.confirm_default') }}</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="applyExisting">{{ __('bank-rules.apply_existing') }}</label>
                            </div>
                        @endif
                        @foreach($errors->all() as $error)<p class="text-xs text-rose-600">{{ $error }}</p>@endforeach
                        <x-filament::button type="submit" size="xs">{{ __('bank.save') }}</x-filament::button>
                    </form>
                @endif
                <details><summary class="cursor-pointer text-xs text-gray-500">{{ __('bank.additional_details') }}</summary>
                <label class="block py-2 text-xs">{{ __('bank-rules.movement_type') }}<select class="fi-select-input rounded-lg" wire:change="assignCategory({{ $transactionDetail->id }}, $event.target.value)"><option value="" @selected(!$transactionDetail->bank_category_id)>{{ __('bank-rules.uncategorized') }}</option>@foreach($categories as $option)@if($option->accounting_treatment !== 'expense' || $option->id === $transactionDetail->bank_category_id)<option value="{{ $option->id }}" @selected($option->id === $transactionDetail->bank_category_id)>{{ $option->accounting_treatment === 'expense' ? __('finance-overview.expenses') : $option->name }}</option>@endif @endforeach</select></label>
                <dl class="mt-2 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['operation_id', 'reference', 'operation_type', 'account_identifier', 'counterparty_account', 'balance_after', 'gross_amount', 'source_file', 'value_date'] as $field)
                        <div><dt class="text-xs text-gray-500">{{ __('bank.'.$field) }}</dt><dd class="break-all">{{ $transactionDetail->$field instanceof \DateTimeInterface ? $transactionDetail->$field->format('d.m.Y') : ($transactionDetail->$field ?? '—') }}</dd></div>
                    @endforeach
                </dl>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" @checked($transactionDetail->exclude_from_pnl) wire:change="markAlreadyRecorded({{ $transactionDetail->id }}, $event.target.checked)"> {{ __('bank-accounting.already_recorded') }}</label>
                <p class="text-xs text-gray-500">{{ __('bank-accounting.exclusion_help') }}</p>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" @checked($transactionDetail->is_legacy) wire:change="markLegacy({{ $transactionDetail->id }}, $event.target.checked)"> {{ __('finance-overview.legacy') }}</label>
                <p class="text-xs text-gray-500">{{ __('finance-overview.legacy_help') }}</p>
                @if((float) $transactionDetail->bank_fee > 0)
                    <p class="text-sm">{{ __('bank-accounting.fee_annotation') }}: {{ number_format($transactionDetail->bank_fee, 2) }} {{ $transactionDetail->currency }}</p>
                    @if($transactionDetail->direction === 'inflow' && $categories->firstWhere('id', $transactionDetail->bank_category_id)?->accounting_treatment === 'settlement')
                        @if($transactionDetail->gross_amount !== null && abs((float) $transactionDetail->gross_amount - (float) $transactionDetail->amount - (float) $transactionDetail->bank_fee) < 0.005)
                            <p class="text-xs text-gray-500">{{ __('bank-accounting.verified_fee') }}</p>
                        @else
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" @checked($transactionDetail->include_embedded_fee) wire:change="includeEmbeddedFee({{ $transactionDetail->id }}, $event.target.checked)"> {{ __('bank-accounting.embedded_fee') }}</label>
                        @endif
                    @endif
                    <p class="text-xs text-gray-500">{{ __('bank-accounting.embedded_fee_help') }}</p>
                @endif
                @error('embedded_fee')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
                <details><summary class="cursor-pointer text-sm text-gray-500">{{ __('bank.raw_data') }}</summary><pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all text-xs">{{ json_encode($transactionDetail->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
                </details>
            </section>
        @endif

        @if($showHistory)
            <section class="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                <h2 class="font-semibold">{{ __('bank.history') }}</h2>
                <div class="overflow-x-auto"><table class="w-full text-left text-xs">
                    <thead class="text-gray-500"><tr>@foreach(['imported_at', 'source_file', 'period', 'account_identifier', 'currency', 'opening_balance', 'closing_balance', 'counts'] as $field)<th class="px-2 py-2 font-medium">{{ __('bank.'.$field) }}</th>@endforeach</tr></thead>
                    <tbody>@forelse($history as $batch)<tr wire:key="bank-batch-{{ $batch->id }}" class="even:bg-gray-50 dark:even:bg-white/5">
                        <td class="whitespace-nowrap px-2 py-2">{{ $batch->imported_at->format('d.m.Y H:i') }}</td>
                        <td class="px-2 py-2"><button class="text-primary-600 hover:underline" wire:click="showBatch({{ $batch->id }})">{{ $batch->source_file }}</button>@if($batch->rolled_back_at)<span class="block text-rose-600">{{ __('bank.rolled_back') }}</span>@endif</td>
                        <td class="whitespace-nowrap px-2 py-2">{{ $batch->period_from?->format('d.m.Y') }} — {{ $batch->period_to?->format('d.m.Y') }}</td>
                        <td class="px-2 py-2">{{ implode(', ', $batch->accounts) ?: '—' }}</td><td class="px-2 py-2">{{ implode(', ', $batch->currencies) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $batch->opening_balance ?? '—' }}</td><td class="px-2 py-2 text-right tabular-nums">{{ $batch->closing_balance ?? '—' }}</td>
                        <td class="px-2 py-2">{{ __('bank.import_summary', ['imported' => $batch->imported_rows, 'duplicates' => $batch->duplicate_rows, 'rejected' => $batch->rejected_rows]) }}</td>
                    </tr>@empty<tr><td colspan="8" class="py-4 text-gray-500">{{ __('bank.no_imports') }}</td></tr>@endforelse</tbody>
                </table></div>
                {{ $history->links() }}
            </section>
        @endif
        @if($batchDetail)
            <section class="space-y-2 rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10">
                <div class="flex items-center justify-between"><h2 class="font-semibold">{{ $batchDetail->source_file }}</h2><x-filament::button size="xs" color="gray" wire:click="showBatch(null)">{{ __('bank.close') }}</x-filament::button></div>
                <p>{{ $batchDetail->period_from?->format('d.m.Y') }} — {{ $batchDetail->period_to?->format('d.m.Y') }} · {{ implode(', ', $batchDetail->currencies) }} · {{ implode(', ', $batchDetail->accounts) ?: '—' }}</p>
                <p>{{ __('bank.import_summary', ['imported' => $batchDetail->imported_rows, 'duplicates' => $batchDetail->duplicate_rows, 'rejected' => $batchDetail->rejected_rows]) }}</p>
                <p>{{ __('bank.opening_balance') }}: {{ $batchDetail->opening_balance ?? '—' }} · {{ __('bank.closing_balance') }}: {{ $batchDetail->closing_balance ?? '—' }}</p>
                @if($batchDetail->rolled_back_at)
                    <p class="text-rose-600">{{ __('bank.rolled_back') }} · {{ $batchDetail->rolled_back_at->format('d.m.Y H:i') }} · {{ $batchDetail->rolled_back_rows }} {{ __('bank.rows') }}</p>
                @else
                    {{ ($this->rollbackImportAction)(['batch' => $batchDetail->id]) }}
                @endif
                @if($batchDetail->rejected_rows)<p class="text-rose-600">{{ __('bank.rejected_help') }}</p>@endif
                <ul class="max-h-64 overflow-auto text-xs text-rose-600">@foreach($batchDetail->errors ?? [] as $error)<li>{{ $error['sheet'] }} · {{ __('bank.row') }} {{ $error['row'] }}: {{ $error['message'] }}</li>@endforeach</ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
