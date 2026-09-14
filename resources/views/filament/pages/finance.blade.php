<x-filament-panels::page>
    @if($historyMode === 'overview')
        @include('filament.pages.finance-overview')
    @else
    @php
        $methodLabels = \App\Enums\PaymentMethod::options();
        $methodBadgeClasses = [
            'cash' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
            'card' => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-300',
            'bank_transfer' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300',
        ];
    @endphp

    <div class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
        <label class="min-w-44 space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>წყარო</span><select wire:model.live="source" class="fi-select-input w-full rounded-lg">@foreach($sourceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label class="min-w-32 space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>პერიოდი</span><select wire:model.live="period" class="fi-select-input w-full rounded-lg">@foreach($periodOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>თარიღი: დან</span><input type="date" lang="ka" wire:model.live="dateFrom" class="fi-input rounded-lg"></label>
        <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>თარიღი: მდე</span><input type="date" lang="ka" wire:model.live="dateUntil" class="fi-input rounded-lg"></label>
    </div>

    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <section class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-400/20 dark:bg-indigo-400/5">
            <div class="text-xs font-medium text-indigo-700 dark:text-indigo-300">მიმდინარე ქეში</div>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-lg font-semibold text-indigo-700 dark:text-indigo-300">
                <span class="whitespace-nowrap">{{ \App\Support\Currency::format($availableBalances['GEL'], 'GEL') }}</span>
                <span class="whitespace-nowrap">{{ \App\Support\Currency::format($availableBalances['USD'], 'USD') }}</span>
            </div>
            <div class="mt-1 space-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                @foreach(['clinic' => 'კლინიკა', 'partner' => 'ისრაელი'] as $balanceSource => $sourceLabel)
                    @continue($source !== 'all' && $source !== $balanceSource)
                    @php
                        $amounts = collect($balancesBySource[$balanceSource])
                            ->filter(fn ($amount) => abs($amount) > 0.005)
                            ->map(fn ($amount, $summaryCurrency) => \App\Support\Currency::format($amount, $summaryCurrency))
                            ->values();
                    @endphp
                    <div><span class="font-medium">{{ $sourceLabel }}:</span> {{ $amounts->isEmpty() ? '—' : $amounts->join(' + ') }}</div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-success-200 bg-success-50/50 p-3 dark:border-success-400/20 dark:bg-success-400/5">
            <div class="text-xs font-medium text-success-700 dark:text-success-300">შემოსავალი</div>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-lg font-semibold text-success-700 dark:text-success-300">
                @foreach($totalsByCurrency as $summaryCurrency => $totals)
                    <span class="whitespace-nowrap">{{ \App\Support\Currency::format($totals['income'], $summaryCurrency) }}</span>
                @endforeach
            </div>
            <div class="mt-1 space-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                @foreach(['clinic' => 'კლინიკა', 'partner' => 'ისრაელი'] as $breakdownSource => $sourceLabel)
                    @continue($source !== 'all' && $source !== $breakdownSource)
                    @php
                        $amounts = collect($sourceBreakdownByCurrency)
                            ->filter(fn ($breakdown) => abs($breakdown[$breakdownSource]['income']) > 0.005)
                            ->map(fn ($breakdown, $summaryCurrency) => \App\Support\Currency::format($breakdown[$breakdownSource]['income'], $summaryCurrency))
                            ->values();
                    @endphp
                    <div><span class="font-medium">{{ $sourceLabel }}:</span> {{ $amounts->isEmpty() ? '—' : $amounts->join(' + ') }}</div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-warning-200 bg-warning-50/50 p-3 dark:border-warning-400/20 dark:bg-warning-400/5">
            <div class="text-xs font-medium text-warning-700 dark:text-warning-300">გასავალი</div>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-lg font-semibold text-warning-700 dark:text-warning-300">
                @foreach($cashOutByCurrency as $summaryCurrency => $amount)
                    <span class="whitespace-nowrap">{{ \App\Support\Currency::format($amount, $summaryCurrency) }}</span>
                @endforeach
            </div>
            <div class="mt-1 space-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                @foreach(['clinic' => 'კლინიკა', 'partner' => 'ისრაელი'] as $breakdownSource => $sourceLabel)
                    @continue($source !== 'all' && $source !== $breakdownSource)
                    @php
                        $amounts = collect($cashOutBreakdownByCurrency)
                            ->filter(fn ($breakdown) => abs($breakdown[$breakdownSource]) > 0.005)
                            ->map(fn ($breakdown, $summaryCurrency) => \App\Support\Currency::format($breakdown[$breakdownSource], $summaryCurrency))
                            ->values();
                    @endphp
                    <div><span class="font-medium">{{ $sourceLabel }}:</span> {{ $amounts->isEmpty() ? '—' : $amounts->join(' + ') }}</div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-danger-200 bg-danger-50/50 p-3 dark:border-danger-400/20 dark:bg-danger-400/5">
            <div class="text-xs font-medium text-danger-700 dark:text-danger-300">ხარჯი</div>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-lg font-semibold text-danger-700 dark:text-danger-300">
                @foreach($totalsByCurrency as $summaryCurrency => $totals)
                    <span class="whitespace-nowrap">{{ \App\Support\Currency::format($totals['expense'], $summaryCurrency) }}</span>
                @endforeach
            </div>
            <div class="mt-1 space-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                @foreach(['clinic' => 'კლინიკა', 'partner' => 'ისრაელი'] as $breakdownSource => $sourceLabel)
                    @continue($source !== 'all' && $source !== $breakdownSource)
                    @php
                        $amounts = collect($sourceBreakdownByCurrency)
                            ->filter(fn ($breakdown) => abs($breakdown[$breakdownSource]['expense']) > 0.005)
                            ->map(fn ($breakdown, $summaryCurrency) => \App\Support\Currency::format($breakdown[$breakdownSource]['expense'], $summaryCurrency))
                            ->values();
                    @endphp
                    <div><span class="font-medium">{{ $sourceLabel }}:</span> {{ $amounts->isEmpty() ? '—' : $amounts->join(' + ') }}</div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="showHistory('payments')" class="fi-btn fi-btn-size-sm rounded-lg px-3 py-2 text-sm font-medium {{ $historyMode === 'payments' ? 'bg-primary-600 text-white hover:bg-primary-500' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200' }}">
            გადახდების ისტორია
        </button>
        <button type="button" wire:click="showHistory('expenses')" class="fi-btn fi-btn-size-sm rounded-lg px-3 py-2 text-sm font-medium {{ $historyMode === 'expenses' ? 'bg-primary-600 text-white hover:bg-primary-500' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200' }}">
            ხარჯების ისტორია
        </button>
        <button type="button" wire:click="showHistory('cash_flow')" class="fi-btn fi-btn-size-sm rounded-lg px-3 py-2 text-sm font-medium {{ $historyMode === 'cash_flow' ? 'bg-primary-600 text-white hover:bg-primary-500' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200' }}">
            ფულადი მოძრაობა
        </button>
        @if($historyMode !== 'overview')
            <button type="button" wire:click="showHistory('overview')" class="ml-auto text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">
                მიმოხილვაზე დაბრუნება
            </button>
        @endif
    </div>

    @if($historyMode !== 'overview')
    <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
        <div class="grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @if(count($currencyOptions) > 1)
                <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>ვალუტა</span><select wire:model.live="{{ $historyMode === 'cash_flow' ? 'cashFlowCurrency' : 'currency' }}" class="fi-select-input w-full rounded-lg">@if($historyMode === 'cash_flow')<option value="">ყველა ვალუტა</option>@endif @foreach($currencyOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            @endif
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>{{ $historyMode === 'cash_flow' ? 'მოძრაობის ტიპი' : 'კატეგორია' }}</span><select wire:model.live="category" class="fi-select-input w-full rounded-lg"><option value="">{{ $historyMode === 'cash_flow' ? 'ყველა მოძრაობა' : 'ყველა კატეგორია' }}</option>@foreach($categoryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>მეთოდი</span><select wire:model.live="paymentMethod" class="fi-select-input w-full rounded-lg"><option value="">ყველა მეთოდი</option>@foreach($methodOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>ძიება</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="პაციენტი ან აღწერა" class="fi-input w-full rounded-lg"></label>
        </div>
        <div class="mt-2 flex justify-end"><button type="button" wire:click="resetFilters" class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">გასუფთავება</button></div>
    </div>
    @endif

    @if(in_array($historyMode, ['payments', 'expenses'], true))
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[64rem] text-sm">
            <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-white/5"><tr><th class="px-3 py-2.5">თარიღი</th><th class="px-3 py-2.5">წყარო</th><th class="px-3 py-2.5">აღწერა</th><th class="px-3 py-2.5">კატეგორია</th><th class="px-3 py-2.5">მეთოდი</th><th class="px-3 py-2.5">ჩაწერა</th><th class="px-3 py-2.5">Visit</th><th class="px-3 py-2.5 text-right">თანხა</th><th class="w-10 px-3 py-2.5"></th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($entries as $entry)
                    <tr wire:key="{{ $entry['key'] }}" class="align-middle {{ ($entry['is_group_child'] ?? false) ? 'bg-gray-50/60 dark:bg-white/[0.02]' : '' }}">
                        <td class="whitespace-nowrap px-3 py-2.5 text-gray-700 dark:text-gray-200">{{ $entry['date']->timezone(config('app.timezone'))->format($entry['has_time'] ? 'd.m.Y H:i' : 'd.m.Y') }}</td>
                        <td class="px-3 py-2.5"><span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $entry['source'] === 'partner' ? 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-300' : ($entry['source'] === 'mixed' ? 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200') }}">{{ match ($entry['source']) { 'partner' => 'ისრაელი', 'mixed' => 'კლინიკა + ისრაელი', default => 'კლინიკა' } }}</span></td>
                        <td class="px-3 py-2.5 {{ ($entry['is_group_child'] ?? false) ? 'pl-7' : '' }}"><div class="{{ in_array($entry['type'], ['patient_payment', 'partner_payment'], true) ? 'renome-patient-name' : (($entry['is_group_parent'] ?? false) ? 'font-semibold' : 'font-medium') }} text-gray-950 dark:text-white">@if($entry['is_group_child'] ?? false)<span class="mr-1 text-gray-400">↳</span>@endif{{ $entry['source_secondary'] ?: $entry['source_title'] }}</div>@if($entry['description'])<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $entry['description'] }}</div>@endif @if(($entry['is_group_parent'] ?? false) && ($entry['group_remaining'] ?? 0) > 0.005)<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">დარჩენილი: {{ \App\Support\Currency::format($entry['group_remaining'], 'GEL') }}</div>@endif</td>
                        <td class="px-3 py-2.5"><span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ \App\Filament\Pages\Finance::typeBadgeClasses($entry['type']) }}">{{ $entry['category'] }}</span></td>
                        <td class="px-3 py-2.5"><div class="flex flex-wrap gap-1">@foreach($entry['methods'] as $method)<span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $methodBadgeClasses[$method] ?? $methodBadgeClasses['cash'] }}">{{ $methodLabels[$method] ?? \App\Enums\PaymentMethod::labelFor($method) }}</span>@endforeach</div></td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-gray-500">{{ $entry['created_by'] ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-gray-500">{{ $entry['visit_id'] ? '#'.$entry['visit_id'] : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ \App\Filament\Pages\Finance::amountTextClasses($entry['type']) }}">@if(isset($entry['display_amount'])){{ $entry['display_amount'] }}@else{{ $entry['type'] === 'expense' ? '−' : '+' }}{{ \App\Support\Currency::format($entry['amount'], $entry['currency']) }}@endif</td>
                        <td class="px-3 py-2.5">@if($entry['manual_id'])<button type="button" wire:click="deleteManualTransaction({{ $entry['manual_id'] }})" wire:confirm="წავშალოთ ჩანაწერი?" class="text-danger-600">×</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="p-8 text-center text-gray-500">არჩეულ პერიოდში ჩანაწერები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    @endif

    @if($historyMode === 'cash_flow')
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[58rem] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-white/5"><tr><th class="px-3 py-2.5">თარიღი</th><th class="px-3 py-2.5">წყარო</th><th class="px-3 py-2.5">ტიპი</th><th class="px-3 py-2.5">საიდან</th><th class="px-3 py-2.5">სად</th><th class="px-3 py-2.5 text-right">თანხა / შედეგი</th><th class="px-3 py-2.5">ჩაწერა</th><th class="px-3 py-2.5">შენიშვნა</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse($entries as $entry)
                        <tr wire:key="{{ $entry['key'] }}">
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $entry['date']->timezone(config('app.timezone'))->format($entry['has_time'] ? 'd.m.Y H:i' : 'd.m.Y') }}</td>
                            <td class="px-3 py-2.5">{{ match ($entry['source']) { 'partner' => 'ისრაელი', 'mixed' => 'კლინიკა + ისრაელი', default => 'კლინიკა' } }}</td>
                            <td class="px-3 py-2.5"><span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ \App\Filament\Pages\Finance::typeBadgeClasses($entry['movement_kind']) }}">{{ $entry['category'] }}</span></td>
                            <td class="px-3 py-2.5">{{ $entry['from_display'] }}</td>
                            <td class="px-3 py-2.5">{{ $entry['to_display'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ \App\Filament\Pages\Finance::amountTextClasses($entry['movement_kind']) }}">{{ $entry['display_amount'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-gray-500">{{ $entry['created_by'] ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-gray-500">{{ $entry['description'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-8 text-center text-gray-500">არჩეულ პერიოდში ფულადი მოძრაობა არ არის.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
    @endif
</x-filament-panels::page>
