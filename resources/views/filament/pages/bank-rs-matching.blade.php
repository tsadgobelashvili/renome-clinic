<div class="space-y-3 text-sm">
    <p class="font-medium">{{ $record->counterparty_name ?: '—' }} · {{ $record->transaction_date->format('d.m.Y') }} · {{ number_format($record->amount, 2) }} {{ $record->currency }}</p>
    <p class="text-xs text-gray-500">{{ __('bank-rs.help') }}</p>
    @foreach($rsErrors as $message)<p class="text-xs text-danger-600">{{ $message }}</p>@endforeach
    @foreach($rsAdvice['errors'] as $message)<p class="text-xs text-danger-600">{{ $message }}</p>@endforeach
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span>{{ __('bank-rs.supplier_'.$rsAdvice['identity']['method']) }}</span>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="rsShowAllSuppliers" class="rounded border-gray-300 text-primary-600 dark:border-white/20 dark:bg-gray-900">{{ __('bank-rs.show_all') }}</label>
    </div>
    <x-filament::input.wrapper>
        <x-filament::input type="search" wire:model.live.debounce.350ms="rsSearch" :placeholder="__('bank-rs.search')" :aria-label="__('bank-rs.search')" />
    </x-filament::input.wrapper>
    @foreach($rsAdvice['combinations'] as $index => $combination)
        <div class="rounded border border-primary-200 bg-primary-50/50 px-2 py-1 text-xs dark:border-primary-500/30 dark:bg-primary-500/5">
            <p class="flex flex-wrap items-center gap-x-2 font-medium"><span class="text-primary-700 dark:text-primary-300">{{ __('bank-rs.likely') }} #{{ $index + 1 }}:</span>{{ trans_choice('bank-rs.document_count', $combination['documents']->count(), ['count' => $combination['documents']->count()]) }} · {{ __('bank-rs.total_short') }} {{ number_format($combination['total_tetri'] / 100, 2) }} GEL
                @if($rsAdvice['selected_tetri'] > 0)<span>{{ __('bank-rs.with_selected') }}: {{ number_format(($combination['total_tetri'] + $rsAdvice['selected_tetri']) / 100, 2) }} GEL</span>@endif
                <span>{{ __('bank-rs.'.($combination['total_tetri'] + $rsAdvice['selected_tetri'] === app(\App\Services\Bank\BankPurchaseMatching::class)->cents($record->amount) ? 'exact_amount' : 'not_exact')) }}</span>
            </p>
            <div class="flex flex-wrap gap-x-3 gap-y-1">
                @foreach($combination['documents'] as $document)
                    <button type="button" class="text-primary-700 hover:underline dark:text-primary-300" wire:click="toggleRsDocument({{ $document->id }})">{{ $document->document_number ?: '#'.$document->id }} · {{ __('bank-rs.'.(array_key_exists($document->id, $rsDocuments) ? 'remove' : 'add')) }}</button>
                @endforeach
            </div>
        </div>
    @endforeach
    @if($rsAdvice['limited'])<p class="text-xs text-gray-500">{{ __('bank-rs.bounded') }}</p>@endif
    <div class="max-h-48 overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs"><caption class="p-2 text-left font-medium">{{ __('bank-rs.documents_list') }}</caption><tbody>
        @forelse($suggestions as $document)
            <tr wire:key="rs-suggestion-{{ $document->id }}" @class(['border-t border-gray-100 dark:border-white/5', 'bg-primary-50/50 dark:bg-primary-500/5' => in_array($document->id, $rsAdvice['suggested_ids'], true)])>
                <td class="px-2 py-1.5">{{ $document->purchase_date->format('d.m.Y') }}<br>{{ $document->document_number ?: '#'.$document->id }} @foreach($rsAdvice['combinations'] as $index => $combination) @if($combination['documents']->contains('id', $document->id))<span class="ml-1 rounded bg-primary-100 px-1 py-0.5 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">{{ __('bank-rs.likely') }} #{{ $index + 1 }}</span>@endif @endforeach</td>
                <td class="max-w-48 truncate px-2 py-1.5" title="{{ $document->supplier_name }}">{{ $document->supplier_name }}</td>
                <td class="whitespace-nowrap px-2 py-1.5 text-right tabular-nums" title="{{ __('bank-rs.outstanding') }}">{{ number_format($document->outstanding, 2) }} GEL</td>
                <td class="px-2 py-1.5 text-right"><x-filament::button size="xs" color="gray" wire:click="toggleRsDocument({{ $document->id }})">{{ __('bank-rs.'.(array_key_exists($document->id, $rsDocuments) ? 'remove' : 'add')) }}</x-filament::button></td>
            </tr>
        @empty<tr><td class="p-2 text-gray-500">{{ __('bank-rs.empty') }}</td></tr>@endforelse
        </tbody></table>
    </div>
    @if($rsAdvice['page'] > 1 || $rsAdvice['has_more'])
        <div class="flex justify-between gap-2"><x-filament::button size="xs" color="gray" :disabled="$rsAdvice['page'] <= 1" wire:click="$set('rsPage', {{ max(1, $rsAdvice['page'] - 1) }})">{{ __('bank-rs.previous') }}</x-filament::button><x-filament::button size="xs" color="gray" :disabled="! $rsAdvice['has_more']" wire:click="$set('rsPage', {{ $rsAdvice['page'] + 1 }})">{{ __('bank-rs.next') }}</x-filament::button></div>
    @endif
    @if($selected->isNotEmpty())
        <h3 class="text-sm font-medium">{{ __('bank-rs.selected') }}</h3>
        @foreach($selected as $document)
            <div wire:key="rs-selected-{{ $document->id }}" class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 flex-1 truncate" title="{{ $document->supplier->name }}">{{ $document->supplier->name }} · {{ $document->document_number ?: '#'.$document->id }}</span>
                <label class="w-40 text-xs text-gray-500">{{ __('bank-rs.amount') }}
                    <x-filament::input.wrapper><x-filament::input type="number" min="0.01" step="0.01" wire:model.live.debounce.350ms="rsDocuments.{{ $document->id }}" /></x-filament::input.wrapper>
                </label>
                <x-filament::icon-button icon="heroicon-o-x-mark" color="gray" size="sm" :label="__('bank-rs.remove')" wire:click="toggleRsDocument({{ $document->id }})" />
            </div>
        @endforeach
        <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums">
            <span>{{ __('bank-rs.bank') }}: {{ number_format($record->amount, 2) }}</span>
            <span>{{ __('bank-rs.total') }}: {{ number_format($selected->sum('total_amount'), 2) }}</span>
            <span>{{ __('bank-rs.difference') }}: {{ number_format($record->amount - $selected->sum('total_amount'), 2) }} GEL</span>
        </p>
        <p class="text-xs text-gray-500">{{ __('bank-rs.difference_help') }}</p>
    @else
        <p class="text-xs text-gray-500">{{ __('bank-rs.remove_help') }}</p>
    @endif
    @if($rsSummary)
        <div class="border-t border-gray-200 pt-2 dark:border-white/10">
            <h3 class="text-xs font-medium">{{ __('bank-rs.saved_breakdown') }} · {{ __('bank-rs.'.$rsSummary->status) }}</h3>
            <p class="my-1 text-xs tabular-nums">{{ __('bank-rs.allocated') }}: {{ number_format($rsSummary->allocated, 2) }} · {{ __('bank-rs.unallocated') }}: {{ number_format($rsSummary->unallocated, 2) }} GEL</p>
            @foreach($rsAllocation as $allocation)
                <p class="flex justify-between gap-2 text-xs"><span>{{ $allocation->expense_direction_id ? app(\App\Services\ExpenseDimensions::class)->labelById($allocation->expense_direction_id) : __('bank-rs.unallocated') }}</span><span>{{ number_format($allocation->amount, 2) }} GEL</span></p>
            @endforeach
        </div>
    @endif
</div>
