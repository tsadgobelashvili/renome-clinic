<div class="space-y-3" data-full-discount-statistics>
    <style>
        .renome-discount-row { display: grid; grid-template-columns: minmax(15rem, 2fr) repeat(5, minmax(6rem, 1fr)); align-items: center; gap: .75rem; min-width: 58rem; padding: .625rem .75rem; font-size: .75rem; }
        .renome-discount-row > :first-child { min-width: 0; text-align: left; }
        .renome-discount-row summary { list-style: none; }
        .renome-discount-summary { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem 1rem; padding: .625rem .75rem; font-size: .75rem; list-style: none; }
        .renome-discount-summary > :first-child { flex: 1 1 12rem; }
        .renome-discount-summary::-webkit-details-marker { display: none; }
        .renome-discount-toolbar { flex-wrap: wrap; gap: .4rem; }
        .renome-discount-toolbar .renome-visits-toolbar__period { flex: 0 0 auto; width: auto; }
        .renome-discount-toolbar input[type=date] { width: 7.4rem; min-width: 0; height: 1.8rem; padding: 0; border: 0; background: transparent; font-size: .75rem; color: inherit; }
        .renome-discount-toolbar .renome-visits-toolbar__doctor { flex: 0 1 auto; min-width: 0; }
        .renome-discount-toolbar select { height: 100%; padding-block: 0; font-size: .75rem; }
        .renome-discount-toolbar [data-filter=source] { width: 6.5rem; }
        .renome-discount-toolbar [data-filter=doctor] { width: 12rem; }
        .renome-discount-toolbar [data-filter=category] { width: 11rem; }
        .renome-discount-toolbar [data-filter=currency] { width: 5.5rem; }
    </style>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2"><h1 class="text-base font-bold">{{ __('discount-statistics.title') }}</h1><button type="button" class="text-gray-400" title="{{ __('discount-statistics.summary_help') }}" aria-label="{{ __('discount-statistics.summary_help') }}">ⓘ</button></div>
        <x-filament::button size="sm" color="gray" wire:click="openDetails">{{ __('discount-statistics.details') }}</x-filament::button>
    </div>

    <section class="renome-visits-toolbar renome-discount-toolbar" aria-label="{{ __('salaries.filters') }}">
        <div class="renome-visits-toolbar__period">
            @foreach (['dateFrom' => 'from', 'dateUntil' => 'until'] as $property => $label)
                <label class="renome-visits-toolbar__date">
                    <span class="fi-sr-only">{{ __('discount-statistics.'.$label) }}</span>
                    <input type="date" wire:model.live="{{ $property }}" aria-label="{{ __('discount-statistics.'.$label) }}" />
                </label>
            @endforeach
        </div>
        <div class="renome-visits-toolbar__presets">
            <div class="renome-visits-toolbar__period-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" class="renome-visits-toolbar__preset renome-visits-toolbar__period-trigger is-active" x-on:click="open = !open" x-bind:aria-expanded="open">
                    {{ __('discount-statistics.'.$period) }} <x-filament::icon icon="heroicon-m-chevron-down" />
                </button>
                <div class="renome-visits-toolbar__period-menu" x-show="open" x-cloak>
                    @foreach (['14_days', '1_month', '6_months', '1_year', 'all'] as $preset)
                        <button type="button" wire:click="applyPeriod('{{ $preset }}')" x-on:click="open = false">{{ __('discount-statistics.'.$preset) }}</button>
                    @endforeach
                </div>
            </div>
        </div>
        @php
            $filterOptions = [
                'source' => ['all' => __('discount-statistics.all'), 'clinic' => __('discount-statistics.clinic'), 'israel-partner' => __('discount-statistics.israeli')],
                'doctor' => ['' => __('discount-statistics.all_doctors')] + $doctorOptions->mapWithKeys(fn ($doctor) => [$doctor->id => $doctor->full_name])->all(),
                'category' => ['' => __('discount-statistics.all_categories')] + $categoryOptions,
                'currency' => ['GEL' => 'GEL', 'USD' => 'USD'],
            ];
        @endphp
        @foreach ($filterOptions as $property => $options)
            <label class="renome-visits-toolbar__doctor" data-filter="{{ $property }}">
                <span class="fi-sr-only">{{ __('discount-statistics.'.$property) }}</span>
                <select wire:model.live="{{ $property }}" aria-label="{{ __('discount-statistics.'.$property) }}">
                    @foreach ($options as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        @endforeach
    </section>

    @php
        $summary = $report['summary'];
    @endphp
    <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        @foreach (['patients', 'visits', 'free_value', 'salary'] as $metric)
            @php
                $metricScope = $metric === 'salary' ? ['salary_status' => 'generated'] : [];
            @endphp
            <button type="button" wire:click='openDetails(@json($metricScope))' data-discount-kpi="{{ $metric }}"
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-left transition hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-white/5">
                <span class="text-[11px] text-gray-500">{{ __('discount-statistics.'.$metric) }}</span>
                <div @class(['mt-1 text-base font-bold tabular-nums', 'text-primary-700 dark:text-primary-300' => $metric === 'salary'])>
                    @if ($metric === 'salary')
                        @include('filament.pages.partials.discount-statistics-salary', ['row' => $summary])
                    @elseif (in_array($metric, ['original_value', 'free_value']))
                        {{ \App\Support\Currency::format($summary->{$metric}, $currency) }}
                    @else
                        {{ number_format($summary->{$metric}) }}
                    @endif
                </div>
            </button>
        @endforeach
    </div>
    @include('filament.pages.partials.discount-statistics-salary-breakdown')
    @if ($summary->itemless_visits > 0)<p class="text-xs text-gray-500">{{ __('discount-statistics.itemless_note', ['count' => $summary->itemless_visits]) }}</p>@endif

    <div class="grid grid-cols-1 items-start gap-3 xl:grid-cols-2" data-discount-breakdown-grid>
    @foreach (['categories', 'doctors', 'reasons'] as $section)
        <section @class(['min-w-0 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900', 'xl:col-span-2' => $section === 'reasons'])>
            <h2 class="px-3 pt-3 text-sm font-bold">{{ __('discount-statistics.'.match ($section) { 'reasons' => 'reasons_title', 'statuses' => 'statuses_title', default => $section }) }}</h2>
            @if(in_array($section, ['categories', 'doctors'], true) && count($report[$section]) > 0)
                @php
                    $discountDonutRows = collect($report[$section])->map(fn ($item) => [
                        'label' => $section === 'categories'
                            ? __('discount-statistics.category_labels.'.$item->category_key)
                            : (trim($item->doctor_first_name.' '.$item->doctor_last_name) ?: __('discount-statistics.no_doctor')),
                        'amount' => (float) $item->free_value,
                    ])->values()->all();
                @endphp
                <div class="p-3">
                    <x-analytics-donut :rows="$discountDonutRows" :currency="$currency" :chart-key="'discount-'.$section" :title="__('discount-statistics.free_value')" />
                </div>
            @endif
            @if ($section === 'reasons')<details><summary class="cursor-pointer px-3 py-2 text-xs text-gray-500">{{ __('discount-statistics.details') }}</summary>
            <div class="renome-discount-row border-b border-gray-100 text-[10px] font-medium text-gray-500 dark:border-white/10">
                <span></span>
                @foreach (['patients', 'visits', 'quantity', 'original_value', 'salary'] as $heading)<span class="text-right">{{ __('discount-statistics.'.$heading) }}</span>@endforeach
            </div>
            @endif
            @forelse ($report[$section] as $row)
                @php
                    $scope = match ($section) {
                        'categories' => ['category_key' => $row->category_key],
                        'doctors' => ['doctor_id' => $row->doctor_id],
                        'reasons' => ['reason' => $row->reason],
                        default => ['salary_status' => $row->salary_status],
                    };
                    $label = match ($section) {
                        'categories' => __('discount-statistics.category_labels.'.$row->category_key),
                        'doctors' => trim($row->doctor_first_name.' '.$row->doctor_last_name) ?: __('discount-statistics.no_doctor'),
                        'reasons' => \App\Services\FullDiscountStatistics::reasonLabel($row->reason),
                        default => __('discount-statistics.status_labels.'.$row->salary_status),
                    };
                @endphp
                @if ($section === 'categories')
                    <details class="border-b border-gray-100 last:border-0 dark:border-white/10" wire:key="discount-category-{{ $row->category_key }}">
                        <summary class="renome-discount-summary cursor-pointer font-bold hover:bg-gray-50 dark:hover:bg-white/5">
                            <span>▸ {{ $label }}</span>
                            <span class="font-normal text-gray-500">{{ number_format($row->quantity) }} {{ __('discount-statistics.work') }} · {{ \App\Support\Currency::format($row->free_value, $currency) }}</span>
                        </summary>
                        <div class="renome-discount-row text-gray-500"><span>{{ __('discount-statistics.details') }}</span>@foreach (['patients', 'visits', 'quantity', 'original_value', 'salary'] as $heading)<span class="text-right">{{ __('discount-statistics.'.$heading) }}</span>@endforeach</div>
                        <div class="renome-discount-row"><span>{{ $label }}</span>@include('filament.pages.partials.discount-statistics-metrics')</div>
                        <div class="renome-discount-row"><button type="button" class="text-primary-600" wire:click='openDetails(@json($scope))'>{{ __('discount-statistics.details') }} · {{ $label }}</button></div>
                        @foreach ($report['groups']->where('category_key', $row->category_key) as $group)
                            @php
                                $groupScope = $scope + ['group_key' => $group->group_key, 'group_type' => $group->group_type];
                            @endphp
                            <div class="renome-discount-row bg-gray-50/50 dark:bg-white/5">
                                <div class="flex items-center justify-between gap-2 pl-3">
                                    <button type="button" class="font-medium" wire:click='expandServices(@json($groupScope))' aria-expanded="{{ $expandedScope === $groupScope ? 'true' : 'false' }}">▸ {{ \App\Services\FullDiscountStatistics::groupLabel($group) }}</button>
                                    <button type="button" class="text-[10px] text-primary-600" wire:click='openDetails(@json($groupScope))'>{{ __('discount-statistics.details') }}</button>
                                </div>
                                @include('filament.pages.partials.discount-statistics-metrics', ['row' => $group])
                            </div>
                            @if ($expandedScope === $groupScope)
                                @include('filament.pages.partials.discount-statistics-services')
                            @endif
                        @endforeach
                    </details>
                @else
                    <div class="border-b border-gray-100 last:border-0 dark:border-white/10" wire:key="discount-{{ $section }}-{{ $loop->index }}">
                        @if ($section === 'doctors')
                            <button type="button" class="renome-discount-summary w-full text-left hover:bg-gray-50 dark:hover:bg-white/5" wire:click='expandServices(@json($scope))' aria-expanded="{{ $expandedScope === $scope ? 'true' : 'false' }}">
                                <span class="font-semibold">▸ {{ $label }}</span>
                                <span class="text-gray-500">{{ number_format($row->quantity) }} {{ __('discount-statistics.work') }} · {{ \App\Support\Currency::format($row->free_value, $currency) }} {{ __('discount-statistics.free_service_short') }}</span>
                                <span class="flex items-center gap-1 text-primary-700 dark:text-primary-300">@include('filament.pages.partials.discount-statistics-salary') <span class="text-gray-500">{{ __('discount-statistics.salary_short') }}</span></span>
                            </button>
                            @if ($expandedScope === $scope)
                                <div class="renome-discount-row text-gray-500"><button type="button" class="text-primary-600" wire:click='openDetails(@json($scope))'>{{ __('discount-statistics.details') }}</button>@foreach (['patients', 'visits', 'quantity', 'original_value', 'salary'] as $heading)<span class="text-right">{{ __('discount-statistics.'.$heading) }}</span>@endforeach</div>
                                <div class="renome-discount-row"><span>{{ $label }}</span>@include('filament.pages.partials.discount-statistics-metrics')</div>
                                @include('filament.pages.partials.discount-statistics-services')
                            @endif
                        @else
                        <div class="renome-discount-row hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" class="text-left font-medium" wire:click='openDetails(@json($scope))'>{{ $label }}</button>
                                <button type="button" class="text-[10px] text-primary-600" wire:click='openDetails(@json($scope))'>{{ __('discount-statistics.details') }}</button>
                            </div>
                            @include('filament.pages.partials.discount-statistics-metrics')
                        </div>
                        @endif
                    </div>
                @endif
            @empty
                <p class="px-3 py-5 text-xs text-gray-500">{{ __('discount-statistics.empty') }}</p>
            @endforelse
            @if ($section === 'reasons')</details>@endif
        </section>
    @endforeach
    </div>

    @if ($detailRows !== null)
        @include('filament.pages.partials.discount-statistics-details')
    @endif
</div>
