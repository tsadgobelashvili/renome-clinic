                        <tr wire:key="bank-transaction-{{ $transaction->id }}" @if($transaction->direction === 'outflow') wire:click="toggleTransaction({{ $transaction->id }})" @endif class="even:bg-gray-50/70 dark:even:bg-white/5 {{ $transaction->direction === 'outflow' ? 'cursor-pointer' : '' }}">
                            <td class="whitespace-nowrap px-2 py-1.5 text-xs">{{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d.m.Y') }}</td>
                            <td class="px-2 py-1.5 text-xs {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ __('bank.'.$transaction->direction) }}</td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-medium tabular-nums {{ $transaction->direction === 'inflow' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ number_format($transaction->amount, 2) }} <span class="text-xs">{{ $transaction->currency }}</span></td>
                            <td class="truncate px-2 py-1.5" title="{{ $transaction->counterparty_name }}">{{ $transaction->counterparty_name ?: '—' }}</td>
                            <td class="truncate px-2 py-1.5" title="{{ $transaction->description }}">{{ \Illuminate\Support\Str::squish($transaction->description ?: '—') }}</td>
                            <td class="px-2 py-1.5 text-xs">
                                @if($transaction->direction === 'inflow')
                                    {{ $categories->firstWhere('id', $transaction->bank_category_id)?->name ?? '—' }}
                                @else
                                    <button type="button" class="block w-full truncate text-left text-primary-600 hover:underline" wire:click.stop="toggleTransaction({{ $transaction->id }})" aria-expanded="{{ $transactionId === $transaction->id ? 'true' : 'false' }}" aria-controls="bank-editor-{{ $transaction->id }}">
                                        @if($rs = $rsClassifications->get($transaction->id))
                                            <span data-rs-bank-classification="{{ $transaction->id }}">
                                                {{ $rs['directions']->count() === 1 ? $rs['directions']->first() : ($rs['directions']->isEmpty() ? 'RS' : __('bank-rs.direction_count', ['count' => $rs['directions']->count()])) }}
                                            </span>
                                            @if($rs['groups']->isNotEmpty())
                                                <span class="block truncate text-gray-500" title="{{ $rs['groups']->implode(', ') }}">{{ $rs['groups']->count() === 1 ? $rs['groups']->first() : __('bank-rs.group_count', ['count' => $rs['groups']->count()]) }}</span>
                                            @endif
                                            @if($rs['unresolved'])<span class="block text-amber-600">{{ __('bank-rs.classification_unresolved') }}</span>@endif
                                            @if($rs['unlinked'])<span class="block text-amber-600">{{ __('bank-rs.unlinked_portion') }}</span>@endif
                                        @else
                                            {{ app(\App\Services\ExpenseDimensions::class)->labelById($transaction->expense_direction_id) }}
                                            <span class="block truncate text-gray-500">{{ app(\App\Services\ExpenseDimensions::class)->labelById($transaction->expense_type_id) }}</span>
                                            <span class="block text-gray-400">{{ __('bank-rules.provenance.'.\App\Models\BankTransaction::classificationMechanism($transaction)) }}</span>
                                            @if($transaction->classification_source !== 'repair' && (! $transaction->expense_direction_id || ! $transaction->expense_type_id) && $transaction->expense_category_id)
                                                <span class="block truncate text-gray-400" title="{{ __('expense-dimensions.legacy') }}">{{ $expenseCategories->firstWhere('id', $transaction->expense_category_id)?->name }} {{ $expenseSubcategories->firstWhere('id', $transaction->expense_subcategory_id)?->name }}</span>
                                            @endif
                                        @endif
                                    </button>
                                    <span class="mt-1 flex items-center gap-1" x-on:click.stop>
                                        {{ ($this->rsMatchingAction)(['transaction' => $transaction->id]) }}
                                        <span class="truncate text-gray-500">{{ __('bank-rs.'.($transaction->rs_status ?? 'unmatched')) }}</span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @if($transactionDetail && $transactionId === $transaction->id && $transaction->direction === 'outflow')
                            <tr wire:key="bank-editor-{{ $transaction->id }}" id="bank-editor-{{ $transaction->id }}" class="bg-gray-50 dark:bg-white/5">
                                <td colspan="6" class="px-3 py-2">
                                    @if($transaction->rs_status)<p class="mb-2 text-xs text-gray-500">{{ __('bank-rs.override_help') }}</p>@endif
                                    @include('filament.pages.bank-inline-category')
                                </td>
                            </tr>
                        @endif
