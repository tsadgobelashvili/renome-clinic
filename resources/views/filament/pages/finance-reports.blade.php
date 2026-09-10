<x-filament-panels::page>
    <div class="space-y-4">
        <nav class="inline-flex items-center gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm dark:border-white/10 dark:bg-gray-900" aria-label="ანგარიშების სექციები">
            @foreach([
                'finance' => app()->getLocale() === 'en' ? 'Finance' : 'ფინანსები',
                'dynamics' => app()->getLocale() === 'en' ? 'Dynamics' : 'დინამიკა',
                'doctors' => app()->getLocale() === 'en' ? 'Doctors' : 'ექიმები',
                'full_discounts' => __('discount-statistics.title'),
            ] as $tab => $label)
                <button type="button" wire:key="reports-tab-{{ $tab }}" wire:click="selectSectionTab('{{ $tab }}')" @if($sectionTab === $tab) style="color: #fff" @endif class="fi-btn fi-btn-size-sm rounded-lg border px-4 py-2 text-sm font-semibold transition-colors {{ $sectionTab === $tab ? 'border-primary-600 bg-primary-600 text-white shadow-sm hover:bg-primary-500' : 'border-transparent bg-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        @if ($sectionTab === 'full_discounts')
            <div wire:key="reports-full-discounts-section">
                @livewire(\App\Filament\Pages\FullDiscountStatistics::class, ['embedded' => true], key('reports-full-discounts-component'))
            </div>
        @else
        <div wire:key="reports-standard-sections">
        <section
            class="renome-visits-toolbar"
            x-data="{
                from: $wire.entangle('dateFrom', true), until: $wire.entangle('dateUntil', true),
                period: $wire.entangle('period', true),
                fromDisplay: '', untilDisplay: '',
                init() { this.syncDisplay(); this.$watch('from', () => this.syncDisplay()); this.$watch('until', () => this.syncDisplay()); this.$watch('period', () => this.syncDisplay()); },
                syncDisplay() { this.fromDisplay = this.period === 'all' ? '' : this.format(this.from); this.untilDisplay = this.period === 'all' ? '' : this.format(this.until); },
                format(value) { if (!value) return ''; const [y,m,d] = String(value).slice(0,10).split('-'); return `${d}.${m}.${y}`; },
                parse(value) { const match = String(value).trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/); if (!match) return null; const iso = `${match[3]}-${match[2]}-${match[1]}`; const date = new Date(`${iso}T00:00:00`); return date.getFullYear() === Number(match[3]) && date.getMonth() + 1 === Number(match[2]) && date.getDate() === Number(match[1]) ? iso : null; },
                update(field) { const display = field === 'from' ? this.fromDisplay : this.untilDisplay; const parsed = this.parse(display); if (parsed || !display) this[field] = parsed || ''; this[field + 'Display'] = this.format(this[field]); },
                pick(field) { const picker = this.$refs[field + 'Picker']; if (picker.showPicker) picker.showPicker(); else picker.click(); },
                picked(field, value) { this[field] = value || ''; this[field + 'Display'] = this.format(value); }
            }"
        >
            <div class="renome-visits-toolbar__period">
                <label class="renome-visits-toolbar__date" x-on:click="pick('from')">
                    <span class="fi-sr-only">დან</span>
                    <input type="text" x-model="fromDisplay" x-on:change="update('from')" x-on:blur="update('from')" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="დან">
                    <button type="button" class="renome-visits-toolbar__calendar" aria-label="კალენდრის გახსნა"><x-filament::icon icon="heroicon-m-calendar-days" /></button>
                    <input x-ref="fromPicker" type="date" class="sr-only" tabindex="-1" x-bind:value="from" x-on:change="picked('from', $event.target.value)">
                </label>
                <span aria-hidden="true">—</span>
                <label class="renome-visits-toolbar__date" x-on:click="pick('until')">
                    <span class="fi-sr-only">მდე</span>
                    <input type="text" x-model="untilDisplay" x-on:change="update('until')" x-on:blur="update('until')" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="მდე">
                    <button type="button" class="renome-visits-toolbar__calendar" aria-label="კალენდრის გახსნა"><x-filament::icon icon="heroicon-m-calendar-days" /></button>
                    <input x-ref="untilPicker" type="date" class="sr-only" tabindex="-1" x-bind:value="until" x-on:change="picked('until', $event.target.value)">
                </label>
            </div>
            @if(in_array($sectionTab, ['doctors', 'dynamics', 'finance'], true))
                <div class="renome-visits-toolbar__presets" aria-label="{{ app()->getLocale() === 'en' ? 'Quick date ranges' : 'სწრაფი პერიოდის არჩევა' }}">
                    @php
                        $reportPeriodLabels = $sectionTab !== 'doctors' ? [
                            '14_days' => app()->getLocale() === 'en' ? '2 weeks' : '2 კვირა',
                            '1_month' => app()->getLocale() === 'en' ? '1 month' : '1 თვე',
                            '3_months' => app()->getLocale() === 'en' ? '3 months' : '3 თვე',
                            '6_months' => app()->getLocale() === 'en' ? '6 months' : '6 თვე',
                            ...($sectionTab === 'finance' ? ['1_year' => app()->getLocale() === 'en' ? '1 year' : '1 წელი'] : []),
                            'all' => app()->getLocale() === 'en' ? 'All' : 'სულ',
                        ] : [
                            '14_days' => app()->getLocale() === 'en' ? '14 days' : '14 დღე',
                            '1_month' => app()->getLocale() === 'en' ? '1 month' : '1 თვე',
                            '6_months' => app()->getLocale() === 'en' ? '6 months' : '6 თვე',
                            '1_year' => app()->getLocale() === 'en' ? '1 year' : '1 წელი',
                            'all' => app()->getLocale() === 'en' ? 'All' : 'სულ',
                        ];
                    @endphp
                    <div class="renome-visits-toolbar__period-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                        <button
                            type="button"
                            class="renome-visits-toolbar__preset renome-visits-toolbar__period-trigger {{ array_key_exists($period, $reportPeriodLabels) ? 'is-active' : '' }}"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open"
                        >
                            <span>{{ $reportPeriodLabels[$period] ?? (app()->getLocale() === 'en' ? 'Custom' : 'მორგებული') }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
                        </button>
                        <div class="renome-visits-toolbar__period-menu" x-show="open" x-cloak>
                            @foreach($reportPeriodLabels as $preset => $label)
                                <button
                                    type="button"
                                    wire:click="{{ match ($sectionTab) { 'finance' => 'applyFinanceDatePreset', 'dynamics' => 'applyDynamicsDatePreset', default => 'applyDoctorsDatePreset' } }}('{{ $preset }}')"
                                    x-on:click="open = false"
                                    class="{{ $period === $preset ? 'is-active' : '' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
                <label class="renome-visits-toolbar__doctor">
                    <span class="fi-sr-only">წყარო</span>
                    <select wire:model.live="source" aria-label="წყარო">
                        @foreach($sourceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="renome-visits-toolbar__doctor">
                    <span class="fi-sr-only">ვალუტა</span>
                    <select wire:model.live="currency" aria-label="ვალუტა">
                        @foreach($currencyOptions as $value => $label)<option value="{{ $value }}">{{ $value }} ({{ $label }})</option>@endforeach
                    </select>
                </label>
        </section>

        <div>
        @if($sectionTab === 'finance')
        <div wire:key="reports-finance-section">
        <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
            @php
                $cards = [
                    ['label' => 'მიმდინარე ქეში', 'value' => $availableBalances[$currency], 'valueClass' => 'text-gray-900 dark:text-white'],
                    ['label' => 'შემოსავალი', 'value' => $totalsByCurrency[$currency]['income'], 'valueClass' => 'text-emerald-500 dark:text-emerald-400'],
                    ['label' => 'გასავალი', 'value' => $cashOutByCurrency[$currency], 'valueClass' => 'text-blue-600 dark:text-blue-400'],
                    ['label' => 'ხარჯი', 'value' => $totalsByCurrency[$currency]['expense'], 'valueClass' => 'text-rose-500 dark:text-rose-400'],
                ];
            @endphp
            @foreach($cards as $card)
                <section class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                        <span class="text-[10px] font-semibold text-gray-400 dark:text-gray-500">{{ $currency }}</span>
                    </div>
                    <div class="mt-1 text-xl font-bold tabular-nums {{ $card['valueClass'] }}">{{ \App\Support\Currency::format($card['value'], $currency) }}</div>
                </section>
            @endforeach
        </div>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-1 border-b border-gray-200 px-3 pt-2 dark:border-white/10">
                @foreach(['income' => 'შემოსავალი', 'expense' => 'ხარჯი', 'cash_out' => 'გასავალი'] as $tab => $label)
                    <button type="button" wire:click="selectReportTab('{{ $tab }}')" class="border-b-2 px-3 py-2 text-sm font-medium transition-colors {{ $reportTab === $tab ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <style>
                .renome-finance-breakdown-row {
                    display: grid;
                    grid-template-columns: .5rem minmax(0, 1fr) auto;
                    align-items: center;
                    justify-content: start;
                    gap: .375rem;
                    white-space: nowrap;
                }

                .renome-finance-breakdown-panel {
                    max-height: 16.25rem;
                    overflow-y: auto;
                    scrollbar-width: thin;
                }

                @media (max-width: 639px) {
                    .renome-finance-breakdown-row {
                        grid-template-columns: .5rem minmax(0, 1fr) auto;
                    }
                }
            </style>

            <div class="mx-auto my-3 w-full max-w-4xl rounded-xl border border-gray-100 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                @if(count($chartRows) > 0)
                <x-analytics-donut :rows="$chartRows" :currency="$currency" :total="$reportTotal" :chart-key="'finance-donut-'.$reportTab" size="13rem" :title="$reportTab">
                <div class="renome-finance-breakdown-panel w-full min-w-0 max-w-xl pr-1" x-data="{ expanded: null }">
                    @forelse($chartRows as $rowIndex => $row)
                        @php($details = $breakdownDetails[$row['key']] ?? [])
                        <div class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <button
                                type="button"
                                x-on:pointerenter="active = {{ $rowIndex }}" x-on:pointerleave="active = null" x-on:focus="active = {{ $rowIndex }}" x-on:blur="active = null"
                                :class="{ 'is-active': active === {{ $rowIndex }} }"
                                class="renome-donut__legend-row renome-finance-breakdown-row w-full py-2 text-left"
                                @if($details !== []) @click="expanded = expanded === '{{ $row['key'] }}' ? null : '{{ $row['key'] }}'" @endif
                            >
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: color({{ $rowIndex }}) }"></span>
                                <span class="min-w-0 truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $row['label'] }}</span>
                                <span class="whitespace-nowrap text-left text-xs tabular-nums">
                                    <strong class="font-bold text-gray-900 dark:text-white">{{ \App\Support\Currency::format($row['amount'], $currency) }}</strong>
                                    <span class="ml-0.5 text-gray-500 dark:text-gray-400">({{ number_format($row['percentage'], 1) }}%)</span>
                                </span>
                            </button>
                            @if($details !== [])
                                <div x-show="expanded === '{{ $row['key'] }}'" x-cloak class="space-y-1 pb-2 pl-4 pr-2">
                                    @foreach($details as $detail)
                                        <div class="flex items-center justify-between gap-3 whitespace-nowrap text-xs">
                                            <span class="min-w-0 truncate text-gray-600 dark:text-gray-400">{{ $detail['name'] }}</span>
                                            <span class="shrink-0 font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ \App\Support\Currency::format($detail['amount'], $currency) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10">არჩეულ პერიოდში მონაცემები არ არის.</div>
                    @endforelse
                </div>
                </x-analytics-donut>
                @else
                    <div class="flex min-h-32 items-center justify-center text-sm text-gray-500 dark:text-gray-400">არჩეულ პერიოდში მონაცემები არ არის.</div>
                @endif
            </div>

            <div class="m-3 overflow-x-auto rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full min-w-[34rem] text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr><th class="w-20 px-3 py-2 text-left">%</th><th class="px-3 py-2 text-left">კატეგორია</th><th class="px-3 py-2 text-left">აღწერა</th><th class="px-3 py-2 text-right">თანხა</th><th class="w-24 px-3 py-2 text-right">რაოდენობა</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse($reportRows as $row)
                            <tr class="even:bg-gray-50/60 dark:even:bg-white/[0.02]">
                                <td class="w-20 px-3 py-2"><span class="inline-flex rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold tabular-nums text-teal-700 dark:bg-teal-950 dark:text-teal-300">{{ number_format($row['percentage'], 1) }}%</span></td>
                                <td class="px-3 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row['label'] }}</td>
                                @php($description = collect($breakdownDescriptions[$row['key']] ?? [])->take(3)->join(' · '))
                                <td class="max-w-xs truncate px-3 py-2 text-xs text-gray-500 dark:text-gray-400" title="{{ $description }}">{{ $description ?: '—' }}</td>
                                <td @class([
                                    'px-3 py-2 text-right font-bold tabular-nums',
                                    'text-emerald-500 dark:text-emerald-400' => $reportTab === 'income',
                                    'text-rose-500 dark:text-rose-400' => $reportTab === 'expense',
                                    'text-blue-600 dark:text-blue-400' => $reportTab === 'cash_out',
                                ])>{{ \App\Support\Currency::format($row['amount'], $currency) }}</td>
                                <td class="px-3 py-2 text-right text-sm tabular-nums text-gray-500">{{ number_format($row['count']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-gray-500">არჩეულ პერიოდში მონაცემები არ არის.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        </div>
        @elseif($sectionTab === 'dynamics')
            <div wire:key="reports-dynamics-section" class="space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">შემოსავალი</span>
                    <div class="mt-1 text-xl font-bold tabular-nums text-emerald-500 dark:text-emerald-400">{{ \App\Support\Currency::format($dynamics['incomeTotal'], $currency) }}</div>
                </section>
                <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">ხარჯი</span>
                    <div class="mt-1 text-xl font-bold tabular-nums text-rose-500 dark:text-rose-400">{{ \App\Support\Currency::format($dynamics['expenseTotal'], $currency) }}</div>
                </section>
            </div>
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                @if(collect($dynamics['income'])->sum() > 0 || collect($dynamics['expense'])->sum() > 0)
                    <div class="h-72 [&_.fi-section]:h-full [&_.fi-section]:border-0 [&_.fi-section]:bg-transparent [&_.fi-section]:shadow-none [&_.fi-section-content]:h-full [&_.fi-section-content]:p-0">
                        @livewire(
                            \App\Filament\Widgets\FinanceDynamicsChart::class,
                            [
                                'labels' => $dynamics['labels'],
                                'income' => $dynamics['income'],
                                'expense' => $dynamics['expense'],
                                'currency' => $currency,
                            ],
                            key('finance-dynamics-'.$source.'-'.$currency.'-'.$dateFrom.'-'.$dateUntil)
                        )
                    </div>
                @else
                    <div class="flex h-56 items-center justify-center text-sm text-gray-500 dark:text-gray-400">არჩეულ პერიოდში მონაცემები არ არის.</div>
                @endif
            </section>
            </div>
        @else
            <div wire:key="reports-doctors-section" class="space-y-4">
                @include('filament.pages.partials.doctor-statistics')
            </div>
        @endif
        </div>
        </div>
        @endif
    </div>
</x-filament-panels::page>
