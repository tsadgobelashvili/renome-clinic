<x-filament-panels::page>
    <x-filament::button tag="a" :href="\App\Filament\Pages\Finance::getUrl()" color="gray">{{ __('bank-accounting.finance') }}</x-filament::button>
    <p class="text-sm text-gray-500">{{ __('finance-overview.opening_help') }}</p>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5"><tr>
            <th class="px-3 py-2">{{ __('finance-overview.effective_date') }}</th><th class="px-3 py-2">{{ __('bank.source') }}</th><th class="px-3 py-2">{{ __('bank.account_identifier') }}</th><th class="px-3 py-2">{{ __('finance-overview.note') }}</th><th class="px-3 py-2 text-right">{{ __('bank.amount') }}</th>
        </tr></thead><tbody>@forelse($openings as $opening)<tr wire:key="opening-{{ $opening->id }}" class="even:bg-gray-50 dark:even:bg-white/5">
            <td class="px-3 py-2">{{ $opening->effective_date->format('d.m.Y') }}</td><td class="px-3 py-2">{{ __('finance-overview.'.$opening->source) }}</td><td class="px-3 py-2">{{ $opening->bank }} {{ $opening->account_identifier }}</td><td class="px-3 py-2">{{ $opening->note }}</td><td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ number_format($opening->amount, 2) }} {{ $opening->currency }}</td>
        </tr>@empty<tr><td colspan="5" class="px-3 py-5 text-center text-gray-500">{{ __('finance-overview.no_openings') }}</td></tr>@endforelse</tbody></table>
    </div>
    {{ $openings->links() }}
</x-filament-panels::page>
