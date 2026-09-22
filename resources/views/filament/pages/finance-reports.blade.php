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
                    <select wire:model.live="{{ $sectionTab === 'finance' ? 'financialCurrency' : 'currency' }}" aria-label="ვალუტა" wire:key="currency-{{ $sectionTab }}">
                        @foreach($currencyOptions as $value => $label)<option value="{{ $value }}">{{ $value === 'all' ? $label : $value.' ('.$label.')' }}</option>@endforeach
                    </select>
                </label>
                @if($sectionTab === 'finance')
                    <label class="renome-visits-toolbar__doctor">
                        <span class="fi-sr-only">ტიპი</span>
                        <select wire:model.live="reportTab" aria-label="ტიპი">
                            <option value="all">ყველა</option>
                            <option value="income">შემოსავალი</option>
                            <option value="expense">ხარჯები</option>
                            <option value="cash_out">გასავალი</option>
                        </select>
                    </label>
                @endif
        </section>

        <div>
        @if($sectionTab === 'finance')
        <div wire:key="reports-finance-section" class="space-y-3">
            @if($analytics['rateUnavailable'])
                <p role="status" class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">GEL ეკვივალენტი მიუწვდომელია: NBG კურსი ვერ ჩაიტვირთა. შეავსეთ ისტორიული კურსები (exchange-rates:backfill) ან აირჩიეთ GEL / USD.</p>
            @else
            @if($financialCurrency === 'all')<p class="text-xs text-gray-500">GEL ეკვივალენტი · ოპერაციის დღის NBG კურსით</p>@endif
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach(['incomeTotal' => ['შემოსავალი', 'text-emerald-600'], 'expenseTotal' => ['ხარჯები', 'text-rose-600'], 'profit' => ['მოგება', 'text-primary-600']] as $key => [$label, $color])
                    <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="text-xs font-medium text-gray-500">{{ $label }}</h3>
                        <div class="mt-1 text-right text-xl font-bold tabular-nums {{ $color }}">{{ \App\Support\Currency::format($analytics[$key], $analytics['displayCurrency']) }}</div>
                    </section>
                @endforeach
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach(['income' => 'შემოსავალი', 'expense' => 'ხარჯები'] as $key => $label)
                    @continue($reportTab !== 'all' && $reportTab !== $key)
                    <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="mb-2 text-sm font-semibold">{{ $label }}</h3>
                        <dl class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($analytics[$key] as $name => $amount)
                                <div class="flex items-center justify-between gap-3 py-2 text-sm">
                                    <dt class="min-w-0 text-gray-600 dark:text-gray-300">{{ $name }}</dt>
                                    <dd class="shrink-0 text-right font-semibold tabular-nums">{{ \App\Support\Currency::format($amount, $analytics['displayCurrency']) }}</dd>
                                </div>
                            @empty
                                <div class="py-3 text-sm text-gray-500">არჩეულ პერიოდში მონაცემები არ არის.</div>
                            @endforelse
                        </dl>
                    </section>
                @endforeach
                @if($reportTab === 'cash_out')
                    <section class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="text-sm font-semibold">გასავალი</h3>
                        <p class="mt-1 text-right font-semibold tabular-nums">{{ \App\Support\Currency::format($analytics['cashOutTotal'], $analytics['displayCurrency']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">გასავალი და ხარჯები განსხვავებული მაჩვენებლებია</p>
                    </section>
                @endif
            </div>
            @livewire(\App\Filament\Widgets\FinanceDynamicsChart::class, [
                ...$analytics['trend'], 'currency' => $analytics['displayCurrency'], 'metric' => $reportTab,
            ], key('finance-analytics-'.$source.'-'.$financialCurrency.'-'.$reportTab.'-'.$period.'-'.$dateFrom.'-'.$dateUntil))
            @endif
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
