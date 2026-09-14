<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex gap-2"><x-filament::button tag="a" :href="\App\Filament\Pages\Finance::getUrl()" color="gray">{{ __('bank-accounting.finance') }}</x-filament::button><x-filament::button tag="a" :href="\App\Filament\Pages\Bank::getUrl()" color="gray">{{ __('bank.title') }}</x-filament::button></div>
        <section class="renome-visits-toolbar flex-wrap">
            <div class="renome-visits-toolbar__period">
                <label class="renome-visits-toolbar__date"><input type="date" wire:model.live="dateFrom" aria-label="{{ __('bank.from') }}"></label><span>—</span>
                <label class="renome-visits-toolbar__date"><input type="date" wire:model.live="dateUntil" aria-label="{{ __('bank.to') }}"></label>
            </div>
            <label class="renome-visits-toolbar__doctor"><select wire:model.live="period" aria-label="{{ __('bank.period') }}">@foreach(__('bank-accounting.periods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="renome-visits-toolbar__doctor"><select wire:model.live="moneySource" aria-label="{{ __('bank-accounting.money_source') }}">@foreach(__('bank-accounting.sources') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="renome-visits-toolbar__doctor"><select wire:model.live="currency" aria-label="{{ __('bank.currency') }}"><option value="">{{ __('bank.all_currencies') }}</option>@foreach($currencies as $code)<option>{{ $code }}</option>@endforeach</select></label>
        </section>
        <p class="text-xs text-gray-500">{{ __('bank-accounting.source_help') }}</p>
        @if($dateError)<p role="alert" class="text-sm text-rose-600">{{ $dateError }}</p>@endif
        <div class="grid gap-3 md:grid-cols-3">
            @foreach(['revenue', 'expenses', 'profit'] as $metric)
                <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-sm text-gray-500">{{ __('bank-accounting.'.$metric) }}</h2>
                    @forelse($totals as $total)<p class="mt-2 text-xl font-semibold tabular-nums">{{ number_format($total->$metric, 2) }} {{ $total->currency }}</p>
                    @empty<p class="mt-2 text-xl font-semibold text-gray-400">0.00 {{ $currency ?: 'GEL' }}</p>@endforelse
                </section>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">{{ __('bank-accounting.pnl_help') }}</p>
    </div>
</x-filament-panels::page>
