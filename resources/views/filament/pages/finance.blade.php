<x-filament-panels::page>
    @php
        $methodLabels = \App\Enums\PaymentMethod::options();
        $methodBadgeClasses = [
            'cash' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
            'card' => 'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-300',
            'bank_transfer' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300',
        ];
    @endphp

    <div class="grid gap-3 sm:grid-cols-3">
        <section class="rounded-xl border border-success-200 bg-success-50/50 p-3.5 dark:border-success-400/20 dark:bg-success-400/5">
            <div class="text-xs font-medium text-success-700 dark:text-success-300">შემოსავალი</div>
            <div class="mt-1 whitespace-nowrap text-xl font-semibold text-success-700 dark:text-success-300">{{ \App\Support\Currency::format($income, $currency) }}</div>
            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @foreach($incomeByMethod as $method => $amount)
                    <span class="whitespace-nowrap">{{ $methodLabels[$method] }} {{ \App\Support\Currency::format($amount, $currency) }}</span>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-danger-200 bg-danger-50/50 p-3.5 dark:border-danger-400/20 dark:bg-danger-400/5">
            <div class="text-xs font-medium text-danger-700 dark:text-danger-300">ხარჯი</div>
            <div class="mt-1 whitespace-nowrap text-xl font-semibold text-danger-700 dark:text-danger-300">{{ \App\Support\Currency::format($expense, $currency) }}</div>
            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @foreach($expenseByMethod as $method => $amount)
                    <span class="whitespace-nowrap">{{ $methodLabels[$method] }} {{ \App\Support\Currency::format($amount, $currency) }}</span>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border p-3.5 {{ $result < 0 ? 'border-danger-200 bg-danger-50/50 dark:border-danger-400/20 dark:bg-danger-400/5' : 'border-indigo-200 bg-indigo-50/50 dark:border-indigo-400/20 dark:bg-indigo-400/5' }}">
            <div class="text-xs font-medium {{ $result < 0 ? 'text-danger-700 dark:text-danger-300' : 'text-indigo-700 dark:text-indigo-300' }}">შედეგი</div>
            <div class="mt-1 whitespace-nowrap text-xl font-semibold {{ $result < 0 ? 'text-danger-700 dark:text-danger-300' : 'text-indigo-700 dark:text-indigo-300' }}">{{ \App\Support\Currency::format($result, $currency) }}</div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">შემოსავალი − ხარჯი</div>
        </section>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
        <div class="grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>თარიღი: დან</span><input type="date" lang="ka" wire:model.live="dateFrom" class="fi-input w-full rounded-lg"></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>თარიღი: მდე</span><input type="date" lang="ka" wire:model.live="dateUntil" class="fi-input w-full rounded-lg"></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>ტიპი</span><select wire:model.live="type" class="fi-select-input w-full rounded-lg"><option value="">ყველა ტიპი</option>@foreach($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>კატეგორია</span><select wire:model.live="category" class="fi-select-input w-full rounded-lg"><option value="">ყველა კატეგორია</option>@foreach($categoryOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>მეთოდი</span><select wire:model.live="paymentMethod" class="fi-select-input w-full rounded-lg"><option value="">ყველა მეთოდი</option>@foreach($methodOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            @if(count($currencyOptions) > 1)
                <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>ვალუტა</span><select wire:model.live="currency" class="fi-select-input w-full rounded-lg">@foreach($currencyOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            @endif
            <label class="space-y-1 text-xs text-gray-500 dark:text-gray-400"><span>ძიება</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="პაციენტი ან აღწერა" class="fi-input w-full rounded-lg"></label>
        </div>
        <div class="mt-2 flex justify-end"><button type="button" wire:click="resetFilters" class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">გასუფთავება</button></div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[58rem] text-sm">
            <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-white/5"><tr><th class="px-3 py-2.5">თარიღი</th><th class="px-3 py-2.5">ტიპი</th><th class="px-3 py-2.5">კატეგორია</th><th class="px-3 py-2.5">წყარო / აღწერა</th><th class="px-3 py-2.5">მეთოდი</th><th class="px-3 py-2.5 text-right">თანხა</th><th class="w-10 px-3 py-2.5"></th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($entries as $entry)
                    <tr wire:key="{{ $entry['key'] }}" class="align-middle">
                        <td class="whitespace-nowrap px-3 py-2.5 text-gray-700 dark:text-gray-200">{{ $entry['date']->timezone(config('app.timezone'))->format($entry['has_time'] ? 'd.m.Y H:i' : 'd.m.Y') }}</td>
                        <td class="px-3 py-2.5"><span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $entry['type'] === 'income' ? 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-300' : 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300' }}">{{ $entry['type'] === 'income' ? 'შემოსავალი' : 'ხარჯი' }}</span></td>
                        <td class="px-3 py-2.5">{{ $entry['category'] }}</td>
                        <td class="px-3 py-2.5"><div class="font-medium text-gray-950 dark:text-white">{{ $entry['source_title'] }}</div>@if($entry['source_secondary'])<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $entry['source_secondary'] }}@if($entry['visit_id']) <span class="whitespace-nowrap">· Visit #{{ $entry['visit_id'] }}</span>@endif</div>@endif</td>
                        <td class="px-3 py-2.5"><div class="flex flex-wrap gap-1">@foreach($entry['methods'] as $method)<span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $methodBadgeClasses[$method] ?? $methodBadgeClasses['cash'] }}">{{ $methodLabels[$method] ?? \App\Enums\PaymentMethod::labelFor($method) }}</span>@endforeach</div></td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold {{ $entry['type'] === 'income' ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $entry['type'] === 'expense' ? '−' : '+' }}{{ \App\Support\Currency::format($entry['amount'], $entry['currency']) }}</td>
                        <td class="px-3 py-2.5">@if($entry['manual_id'])<button type="button" wire:click="deleteManualTransaction({{ $entry['manual_id'] }})" wire:confirm="წავშალოთ ჩანაწერი?" class="text-danger-600">×</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-8 text-center text-gray-500">არჩეულ პერიოდში ჩანაწერები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
