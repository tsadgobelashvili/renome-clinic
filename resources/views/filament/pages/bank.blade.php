<x-filament-panels::page>
    <div class="space-y-3" wire:init="refreshBogBalance" x-data="{ feesOpen: false }">
        <div class="grid gap-2 sm:grid-cols-3">
            <section class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-400/20 dark:bg-indigo-400/5">
                <h2 class="text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('bank.balance') }}</h2>
                <p wire:loading wire:target="refreshBogBalance,mountAction('syncBog')" role="status" class="mt-1 text-xs text-gray-500">{{ __('bog-transactions.balance_loading') }}</p>
                <div wire:loading.remove wire:target="refreshBogBalance,mountAction('syncBog')">
                    @if($bogBalanceFailed)
                        <p role="status" class="mt-1 text-xs text-gray-500">{{ __('bog-transactions.balance_unavailable') }}</p>
                    @elseif($bogBalance)
                        <div class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($bogBalance['reported_balance'], 2) }} {{ $bogBalance['currency'] }}</div>
                        <div class="break-all text-xs text-gray-500">{{ $bogBalance['account_identifier'] }}</div>
                        <p class="text-xs text-gray-500">{{ __('bog-transactions.live_balance') }} · {{ \Carbon\Carbon::parse($bogBalance['fetched_at'])->format('d.m.Y H:i') }}</p>
                    @else
                        <p role="status" class="mt-1 text-xs text-gray-500">{{ __('bog-transactions.balance_loading') }}</p>
                    @endif
                </div>
            </section>
            @foreach(['expenses', 'fees'] as $metric)
                <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                    @if($metric === 'fees')
                        <button type="button" class="block w-full text-left" x-on:click="feesOpen = !feesOpen" x-bind:aria-expanded="feesOpen" aria-controls="bank-fee-breakdown">
                    @endif
                    <h2 class="text-xs font-medium text-gray-500">{{ __('bank.'.$metric) }} @if($metric === 'fees')<span aria-hidden="true" class="float-right" x-text="feesOpen ? '−' : '+'"></span>@endif</h2>
                    @forelse($totals as $total)
                        <div class="mt-1 text-lg font-semibold tabular-nums {{ (float) $total->$metric === 0.0 ? 'text-gray-400' : ($metric === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') }}">{{ number_format($total->$metric, 2) }} {{ $total->currency }}</div>
                    @empty
                        <div class="mt-1 text-lg font-semibold text-gray-400">0.00 {{ $currency ?: 'GEL' }}</div>
                    @endforelse
                    <p class="mt-1 text-xs text-gray-500">{{ __('bank.filtered_period') }}</p>
                    @if($metric === 'fees')</button>@endif
                </section>
            @endforeach
        </div>

        <div id="bank-fee-breakdown" x-show="feesOpen" x-cloak class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900">
            @foreach(['card_fees', 'transfer_fees'] as $feeType)
                <div class="flex flex-wrap items-center justify-between gap-2 py-1">
                    <span>{{ __('bank.'.$feeType) }}</span>
                    <span class="flex gap-3 font-medium tabular-nums">
                        @forelse($totals as $total)
                            <span>{{ number_format($total->$feeType, 2) }} {{ $total->currency }}</span>
                        @empty
                            <span>0.00 {{ $currency ?: 'GEL' }}</span>
                        @endforelse
                    </span>
                </div>
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
            @foreach(['direction' => ['expenseDirection', $directionOptions], 'type' => ['expenseType', $typeOptions]] as $dimension => [$property, $options])
                <label class="renome-visits-toolbar__doctor"><select wire:model.live="{{ $property }}" aria-label="{{ __('expense-dimensions.'.$dimension) }}">
                    <option value="">{{ __('expense-dimensions.all_'.($dimension === 'type' ? 'types' : 'directions')) }}</option>
                    @foreach($options as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select></label>
            @endforeach
            <label class="renome-visits-toolbar__search"><span class="fi-sr-only">{{ __('bank.search') }}</span><input type="search" wire:model.live.debounce.400ms="search" maxlength="255" placeholder="{{ __('bank.search') }}"></label>
            <x-filament::button size="xs" :color="$uncategorizedExpenses ? 'primary' : 'gray'" wire:click="$toggle('uncategorizedExpenses')" :aria-pressed="$uncategorizedExpenses ? 'true' : 'false'">
                {{ __('bank.uncategorized_expenses') }}
            </x-filament::button>
        </section>
        @if($dateError)<p role="alert" class="text-sm text-rose-600">{{ $dateError }}</p>@endif
        @error('category')<p role="alert" class="text-sm text-rose-600">{{ $message }}</p>@enderror

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full min-w-[48rem] table-fixed text-left text-sm">
                <colgroup>
                    <col class="w-24"><col class="w-24"><col class="w-32">
                    <col class="w-1/5"><col><col class="w-1/5">
                </colgroup>
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
                    @foreach(['date', 'direction', 'amount', 'counterparty', 'description', 'category'] as $column)
                        <th scope="col" class="px-2 py-2 font-medium {{ $column === 'amount' ? 'text-right' : '' }}">{{ __($column === 'pnl_status' ? 'bank-accounting.treatment' : 'bank.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($transactions as $transaction)
                        <tr wire:key="bank-transaction-{{ $transaction->id }}" @if($transaction->direction === 'outflow') wire:click="toggleTransaction({{ $transaction->id }})" @endif class="even:bg-gray-50/70 dark:even:bg-white/5 {{ $transaction->direction === 'outflow' ? 'cursor-pointer' : '' }}">
                            <td class="whitespace-nowrap px-2 py-1.5 text-xs">{{ $transaction->transaction_date->format('d.m.Y') }}</td>
                            <td class="px-2 py-1.5 text-xs {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ __('bank.'.$transaction->direction) }}</td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-medium tabular-nums {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ number_format($transaction->amount, 2) }} <span class="text-xs">{{ $transaction->currency }}</span></td>
                            <td class="truncate px-2 py-1.5" title="{{ $transaction->counterparty_name }}">{{ $transaction->counterparty_name ?: '—' }}</td>
                            <td class="truncate px-2 py-1.5" title="{{ $transaction->description }}">{{ \Illuminate\Support\Str::squish($transaction->description ?: '—') }}</td>
                            <td class="px-2 py-1.5 text-xs">
                                @if($transaction->direction === 'inflow')
                                    {{ $categories->firstWhere('id', $transaction->bank_category_id)?->name ?? '—' }}
                                @else
                                    <button type="button" class="block w-full truncate text-left text-primary-600 hover:underline" wire:click.stop="toggleTransaction({{ $transaction->id }})" aria-expanded="{{ $transactionId === $transaction->id ? 'true' : 'false' }}" aria-controls="bank-editor-{{ $transaction->id }}">
                                        {{ app(\App\Services\ExpenseDimensions::class)->labelById($transaction->expense_direction_id) }}
                                        <span class="block truncate text-gray-500">{{ app(\App\Services\ExpenseDimensions::class)->labelById($transaction->expense_type_id) }}</span>
                                        @if((! $transaction->expense_direction_id || ! $transaction->expense_type_id) && $transaction->expense_category_id)
                                            <span class="block truncate text-gray-400" title="{{ __('expense-dimensions.legacy') }}">{{ $expenseCategories->firstWhere('id', $transaction->expense_category_id)?->name }} {{ $expenseSubcategories->firstWhere('id', $transaction->expense_subcategory_id)?->name }}</span>
                                        @endif
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if($transactionDetail && $transactionId === $transaction->id && $transaction->direction === 'outflow')
                            <tr wire:key="bank-editor-{{ $transaction->id }}" id="bank-editor-{{ $transaction->id }}" class="bg-gray-50 dark:bg-white/5">
                                <td colspan="6" class="px-3 py-2">
                                    @include('filament.pages.bank-inline-category')
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500">{{ __('bank.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}

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
