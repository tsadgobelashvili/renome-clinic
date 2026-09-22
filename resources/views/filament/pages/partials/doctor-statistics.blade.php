<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">სულ პაციენტები</span>
        <div class="mt-1 text-xl font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($doctorStatistics['totalPatients']) }}</div>
    </section>
    <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">შემოსავალი</span>
        <div class="mt-1 text-xl font-bold tabular-nums text-emerald-500 dark:text-emerald-400">{{ \App\Support\Currency::format($doctorStatistics['totalRevenue'], $currency) }}</div>
    </section>
</div>

@php
    $compactChartRows = static function ($items, array $colors): array {
        $rows = collect($items)
            ->filter(fn (array $item): bool => (float) $item['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        if ($rows->count() > 5) {
            $otherRows = $rows->slice(4);
            $rows = $rows->take(4)->push([
                'label' => app()->getLocale() === 'en' ? 'Others' : 'სხვა',
                'amount' => (float) $otherRows->sum('amount'),
                'percentage' => (float) $otherRows->sum('percentage'),
            ]);
        }

        return $rows->values()->map(function (array $row, int $index) use ($colors): array {
            $row['color'] = $colors[$index] ?? '#94a3b8';

            return $row;
        })->all();
    };
    $categoryChartRows = $compactChartRows(
        collect($doctorStatistics['categories'])->map(fn (array $category): array => [
            'label' => $category['label'],
            'amount' => (float) $category['revenue'],
            'percentage' => (float) $category['percentage'],
        ]),
        ['#5eead4', '#60a5fa', '#c4b5fd', '#fbbf24', '#fb7185'],
    );
    $doctorChartRows = $compactChartRows(
        collect($doctorStatistics['doctors'])->map(fn (array $doctor): array => [
            'label' => $doctor['name'],
            'amount' => (float) $doctor['revenue'],
            'percentage' => (float) $doctor['percentage'],
        ]),
        ['#60a5fa', '#5eead4', '#c4b5fd', '#fbbf24', '#fb7185'],
    );
@endphp

@if(count($categoryChartRows) > 1 || count($doctorChartRows) > 1)
    <div class="grid grid-cols-1 gap-3 xl:grid-cols-2" data-doctor-statistics-charts>
        @if(count($categoryChartRows) > 1)
            @include('filament.pages.partials.statistics-donut', [
                'chartKey' => 'category-share',
                'title' => app()->getLocale() === 'en' ? 'Category share' : 'კატეგორიების წილი',
                'rows' => $categoryChartRows,
            ])
        @endif
        @if(count($doctorChartRows) > 1)
            @include('filament.pages.partials.statistics-donut', [
                'chartKey' => 'doctor-share',
                'title' => app()->getLocale() === 'en' ? 'Doctor share' : 'ექიმების წილი',
                'rows' => $doctorChartRows,
            ])
        @endif
    </div>
@endif

<div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
    <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">კონსულტაციების კონვერსია</h2>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-5">
            <button type="button" wire:click="toggleTotalConsultationPatients"
                aria-expanded="{{ $showTotalConsultationPatients ? 'true' : 'false' }}"
                class="rounded-lg bg-gray-50 px-3 py-2 text-left transition hover:bg-gray-100 dark:bg-white/[0.04] dark:hover:bg-white/10">
                <div class="flex items-center justify-between gap-1 text-[10px] font-medium leading-4 text-gray-500 dark:text-gray-400">
                    <span>სულ პაციენტი</span>
                    <x-filament::icon icon="{{ $showTotalConsultationPatients ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="size-3.5 shrink-0" />
                </div>
                <div class="mt-0.5 text-base font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($doctorStatistics['consultations']['total']) }}</div>
            </button>
            @foreach([
                ['label' => 'დაიწყო მკურნალობა', 'value' => number_format($doctorStatistics['consultations']['started'])],
                ['label' => 'მოლოდინში', 'value' => number_format($doctorStatistics['consultations']['pending'])],
            ] as $metric)
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.04]">
                    <div class="text-[10px] font-medium leading-4 text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-gray-900 dark:text-white">{{ $metric['value'] }}</div>
                </div>
            @endforeach
            <button
                type="button"
                wire:click="toggleNotStartedPatients"
                aria-expanded="{{ $showNotStartedPatients ? 'true' : 'false' }}"
                class="rounded-lg bg-red-50 px-3 py-2 text-left transition hover:bg-red-100 dark:bg-red-950/30 dark:hover:bg-red-950/50"
            >
                <div class="flex items-center justify-between gap-1 text-[10px] font-medium leading-4 text-red-600 dark:text-red-300">
                    <span>არ დაიწყო</span>
                    <x-filament::icon icon="{{ $showNotStartedPatients ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="size-3.5 shrink-0" />
                </div>
                <div class="mt-0.5 text-base font-bold tabular-nums text-red-700 dark:text-red-300">{{ number_format($doctorStatistics['consultations']['notStarted']) }}</div>
            </button>
            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.04]">
                <div class="text-[10px] font-medium leading-4 text-gray-500 dark:text-gray-400">კონვერსია</div>
                <div class="mt-0.5 text-base font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($doctorStatistics['consultations']['conversion'], 1) }}%</div>
            </div>
        </div>

        @if($showNotStartedPatients || $showTotalConsultationPatients)
            @php($consultationList = $showTotalConsultationPatients ? 'totalPatients' : 'notStartedPatients')
            <div wire:key="consultation-{{ $consultationList }}-details" class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full min-w-[40rem] text-xs">
                    <thead class="bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2 text-left">პაციენტი</th>
                            <th class="px-3 py-2 text-left">ტელეფონი</th>
                            <th class="px-3 py-2 text-left">კონსულტაცია</th>
                            <th class="px-3 py-2 text-left">ექიმი</th>
                            <th class="px-3 py-2 text-right">გასული დღე</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($doctorStatistics['consultations'][$consultationList] as $patient)
                            <tr wire:key="{{ $consultationList }}-patient-{{ $patient['id'] }}" class="text-gray-700 dark:text-gray-300">
                                <td class="whitespace-nowrap px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ $patient['patient'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $patient['phone'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $patient['consultationDate'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $patient['doctor'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($patient['days']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-5 text-center text-gray-500">პაციენტები არ არის.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">CT / პანორამა</h2>
        <div class="grid grid-cols-2 gap-2">
            @foreach([
                ['label' => '3D CT', 'data' => $doctorStatistics['tomography']['ct']],
                ['label' => 'პანორამა', 'data' => $doctorStatistics['tomography']['panorama']],
            ] as $item)
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.04]">
                    <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $item['label'] }}</div>
                    <div class="mt-1 flex items-baseline gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span><strong class="text-base tabular-nums text-gray-900 dark:text-white">{{ number_format($item['data']['patients']) }}</strong> პაციენტი</span>
                        <span><strong class="text-base tabular-nums text-gray-900 dark:text-white">{{ number_format($item['data']['quantity']) }}</strong> რაოდენობა</span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>

<section x-data="{ expanded: {} }" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">მანიპულაციების ჯგუფები</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[32rem] text-sm">
            <thead class="bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr><th class="px-4 py-2 text-left">კატეგორია / ჯგუფი</th><th class="px-3 py-2 text-right">პაციენტები</th><th class="px-3 py-2 text-right">რაოდენობა</th><th class="px-4 py-2 text-right">თანხა</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($doctorStatistics['treatmentHierarchy'] as $category)
                    @php($expandable = ! ($category['key'] === 'other' && count($category['groups']) === 1 && $category['groups'][0]['key'] === 'other'))
                    <tr wire:key="treatment-statistics-category-{{ $category['key'] }}" data-statistics-category-row="{{ $category['key'] }}" class="bg-gray-100/80 dark:bg-white/[0.06]">
                        <td class="px-4 py-2 text-gray-900 dark:text-white">
                            @if($expandable)
                                <button
                                    type="button"
                                    x-on:click="expanded['{{ $category['key'] }}'] = ! expanded['{{ $category['key'] }}']"
                                    x-bind:aria-expanded="expanded['{{ $category['key'] }}'] ? 'true' : 'false'"
                                    class="inline-flex items-center gap-2 font-bold"
                                >
                                    <x-filament::icon x-show="! expanded['{{ $category['key'] }}']" icon="heroicon-m-chevron-right" class="size-4 shrink-0 text-gray-500" />
                                    <x-filament::icon x-cloak x-show="expanded['{{ $category['key'] }}']" icon="heroicon-m-chevron-down" class="size-4 shrink-0 text-gray-500" />
                                    <span>{{ $category['label'] }}</span>
                                    <span class="text-xs font-semibold tabular-nums text-gray-500 dark:text-gray-400">×{{ number_format($category['quantity']) }}</span>
                                </button>
                            @else
                                <div class="inline-flex items-center gap-2 pl-6 font-bold">
                                    <span>{{ $category['label'] }}</span>
                                    <span class="text-xs font-semibold tabular-nums text-gray-500 dark:text-gray-400">×{{ number_format($category['quantity']) }}</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($category['patients']) }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($category['quantity']) }}</td>
                        <td class="px-4 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($category['amount'], $currency) }}</td>
                    </tr>
                    @if($expandable)
                        @foreach($category['groups'] as $group)
                        <tr
                            wire:key="treatment-statistics-group-{{ $category['key'] }}-{{ $group['key'] }}"
                            data-statistics-group-row="{{ $category['key'] }}-{{ $group['key'] }}"
                            x-cloak
                            x-show="expanded['{{ $category['key'] }}']"
                            class="bg-white dark:bg-gray-800"
                        >
                            <td class="px-4 py-2 pl-7 font-semibold text-gray-800 dark:text-gray-200">
                                <div>{{ $group['label'] }}</div>
                                @if(! empty($group['breakdown']) || ! empty($group['israeliQuantity']))
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-normal text-gray-500 dark:text-gray-400">
                                        @foreach($group['breakdown'] ?? [] as $brand)
                                            <span>{{ $brand['label'] }} ×{{ number_format($brand['quantity']) }}</span>
                                        @endforeach
                                        @if(! empty($group['israeliQuantity']))
                                            <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-800">{{ app()->getLocale() === 'en' ? 'Israeli' : 'ისრაელი' }} ×{{ number_format($group['israeliQuantity']) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($group['patients']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($group['quantity']) }}</td>
                            <td class="px-4 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($group['amount'], $currency) }}</td>
                        </tr>
                        @endforeach
                    @endif
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-xs text-gray-500">არჩეულ პერიოდში მანიპულაციები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">კატეგორიები</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] text-sm">
            <thead class="bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr><th class="px-4 py-2 text-left">კატეგორია</th><th class="px-3 py-2 text-right">პაციენტები</th><th class="px-3 py-2 text-right">სამუშაო</th><th class="px-3 py-2 text-right">შემოსავალი</th><th class="px-4 py-2 text-right">წილი</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($doctorStatistics['categories'] as $category)
                    <tr wire:key="doctor-category-{{ $category['key'] }}" class="even:bg-gray-50/60 dark:even:bg-white/[0.02]">
                        <td class="px-4 py-2 font-semibold text-gray-800 dark:text-gray-200">
                            {{ $category['label'] }}
                            @if(($category['work_breakdown'] ?? []) !== [])
                                <div class="mt-0.5 text-[11px] font-normal text-gray-500 dark:text-gray-400">
                                    @foreach($category['work_breakdown'] as $work)
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                            <span>{{ $work['label'] }} ×{{ number_format($work['quantity']) }}</span>
                                            @if(($work['source'] ?? null) === 'israeli')
                                                <span data-stat-source="israeli" class="inline-flex rounded-md bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-800">{{ app()->getLocale() === 'en' ? 'Israeli' : 'ისრაელი' }}</span>
                                            @endif
                                        </span>@if(! $loop->last)<span> · </span>@endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($category['patients']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($category['procedures']) }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($category['revenue'], $currency) }}</td>
                        <td class="px-4 py-2 text-right text-xs font-semibold tabular-nums text-gray-500">{{ number_format($category['percentage'], 1) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">არჩეულ პერიოდში მონაცემები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">ექიმების რეიტინგი</h2></div>
    <div class="divide-y divide-gray-100 dark:divide-gray-700">
        @forelse($doctorStatistics['doctors'] as $doctor)
            <div wire:key="doctor-stat-row-{{ $doctor['id'] }}">
                <button type="button" wire:click="toggleDoctor({{ $doctor['id'] }})" class="grid w-full grid-cols-1 gap-2 px-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03] sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="{{ $selectedDoctorId === $doctor['id'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right' }}" class="size-4 shrink-0 text-gray-400" />
                            <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $doctor['name'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $doctor['patients'] }} პაციენტი · {{ $doctor['procedures'] }} ვიზიტი/სამუშაო</span>
                        </div>
                        <div class="ml-6 mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-primary-500" style="width: {{ min(100, $doctor['percentage']) }}%"></div></div>
                    </div>
                    <div class="flex items-baseline gap-2 whitespace-nowrap text-right">
                        <strong class="text-sm tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($doctor['revenue'], $currency) }}</strong>
                        <span class="text-xs tabular-nums text-gray-500">{{ number_format($doctor['percentage'], 1) }}%</span>
                    </div>
                </button>

                @if($selectedDoctorId === $doctor['id'])
                    @php($detail = $doctorStatistics['details'][$doctor['id']])
                    <div wire:key="doctor-details-{{ $doctor['id'] }}" class="border-t border-gray-100 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-white/[0.02]">
                        <div wire:key="doctor-dynamics-panel-{{ $doctor['id'] }}" class="mb-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">შემოსავლის დინამიკა</h3>
                                <select wire:model.live="doctorDynamicsCurrency" class="h-8 rounded-lg border-gray-300 py-1 pl-2 pr-7 text-xs dark:border-gray-600 dark:bg-gray-900">
                                    <option value="GEL">GEL</option>
                                    <option value="USD">USD</option>
                                    <option value="both">GEL / USD</option>
                                </select>
                            </div>
                            @if($detail['dynamics']['hasData'])
                                <div class="h-60 [&_.fi-section]:h-full [&_.fi-section]:border-0 [&_.fi-section]:bg-transparent [&_.fi-section]:shadow-none [&_.fi-section-content]:h-full [&_.fi-section-content]:p-0">
                                    @livewire(
                                        \App\Filament\Widgets\DoctorDynamicsChart::class,
                                        [
                                            'labels' => $detail['dynamics']['labels'],
                                            'series' => $detail['dynamics']['series'],
                                        ],
                                        key('doctor-dynamics-'.$doctor['id'].'-'.$doctorDynamicsCurrency.'-'.$source.'-'.$dateFrom.'-'.$dateUntil)
                                    )
                                </div>
                            @else
                                <div class="flex h-28 items-center justify-center text-xs text-gray-500 dark:text-gray-400">არჩეულ პერიოდში მონაცემები არ არის.</div>
                            @endif
                        </div>
                        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">კატეგორიები</h3>
                                    <div class="space-y-2">
                                        @foreach($detail['categories'] as $category)
                                            <div wire:key="doctor-detail-category-{{ $doctor['id'] }}-{{ $category['key'] }}">
                                                <div class="flex items-center justify-between gap-2 text-xs"><span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $category['label'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ number_format($category['percentage'], 1) }}%</span></div>
                                                @foreach($category['work_breakdown'] ?? [] as $work)
                                                    <div class="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                                        <span class="truncate">{{ $work['label'] }}</span>
                                                        <span class="flex shrink-0 items-center gap-1 tabular-nums">
                                                            <span>×{{ number_format($work['quantity']) }}</span>
                                                            @if(($work['source'] ?? null) === 'israeli')
                                                                <span data-stat-source="israeli" class="inline-flex rounded-md bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-800">{{ app()->getLocale() === 'en' ? 'Israeli' : 'ისრაელი' }}</span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                @endforeach
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-emerald-400" style="width: {{ min(100, $category['percentage']) }}%"></div></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">შესრულებული სამუშაოები</h3>
                                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @forelse($detail['procedures'] as $procedure)
                                            <div wire:key="doctor-procedure-{{ $doctor['id'] }}-{{ md5($procedure['name'].'|'.($procedure['source'] ?? 'clinic')) }}" class="flex items-center justify-between gap-2 py-1.5 text-xs">
                                                <span class="flex min-w-0 items-center gap-1 text-gray-700 dark:text-gray-300">
                                                    <span class="truncate">{{ $procedure['name'] }} ×{{ $procedure['count'] }}</span>
                                                    @if(($procedure['source'] ?? null) === 'israeli')
                                                        <span data-stat-source="israeli" class="inline-flex shrink-0 rounded-md bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-800">{{ app()->getLocale() === 'en' ? 'Israeli' : 'ისრაელი' }}</span>
                                                    @endif
                                                </span>
                                                @if(! ($procedure['quantity_only'] ?? false))<strong class="shrink-0 tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($procedure['revenue'], $currency) }}</strong>@endif
                                            </div>
                                        @empty
                                            <div class="py-4 text-center text-xs text-gray-500">სამუშაოები არ არის.</div>
                                        @endforelse
                                    </div>
                                </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">არჩეულ პერიოდში ექიმების მონაცემები არ არის.</div>
        @endforelse
    </div>
</section>
