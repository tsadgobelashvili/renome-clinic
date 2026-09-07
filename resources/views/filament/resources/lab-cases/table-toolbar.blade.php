@php
    $datePresets = [
        '14' => ['from' => today()->subDays(13)->toDateString(), 'until' => today()->toDateString()],
        'month' => ['from' => today()->subMonthNoOverflow()->toDateString(), 'until' => today()->toDateString()],
    ];
    $periodLabels = collect(array_keys($datePresets))->mapWithKeys(fn ($key) => [$key => __('lab.periods.'.$key)])->all();
    $doctors = \App\Models\Doctor::orderBy('first_name')->orderBy('last_name')->get();
@endphp
<div
    class="renome-visits-toolbar renome-lab-filters"
    aria-label="{{ __('lab.cases') }}"
    x-data="{
        from: $wire.entangle('tableFilters.toolbar.from', true),
        until: $wire.entangle('tableFilters.toolbar.until', true),
        presets: @js($datePresets),
        presetLabels: @js($periodLabels),
        fromDisplay: '',
        untilDisplay: '',
        calendarOpen: false,
        activeDateField: 'from',
        rangeDraftStart: null,
        rangeDraftEnd: null,
        calendarYear: new Date().getFullYear(),
        calendarMonth: new Date().getMonth(),
        init() {
            this.from = this.from ? String(this.from).slice(0, 10) : null
            this.until = this.until ? String(this.until).slice(0, 10) : null
            this.fromDisplay = this.formatDate(this.from)
            this.untilDisplay = this.formatDate(this.until)
            this.$watch('from', (value) => this.fromDisplay = this.formatDate(value))
            this.$watch('until', (value) => this.untilDisplay = this.formatDate(value))
        },
        formatDate(value) {
            if (! value) return ''
            const [year, month, day] = String(value).slice(0, 10).split('-')
            return day && month && year ? `${day}.${month}.${year}` : ''
        },
        parseDate(value) {
            const match = String(value).trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/)
            if (! match) return null
            const [, day, month, year] = match
            const iso = `${year}-${month}-${day}`
            const date = new Date(`${iso}T00:00:00`)
            return date.getFullYear() === Number(year) && date.getMonth() + 1 === Number(month) && date.getDate() === Number(day) ? iso : null
        },
        updateFrom() {
            const parsed = this.parseDate(this.fromDisplay)
            if (parsed || ! this.fromDisplay) this.from = parsed
            this.fromDisplay = this.formatDate(this.from)
        },
        updateUntil() {
            const parsed = this.parseDate(this.untilDisplay)
            if (parsed || ! this.untilDisplay) this.until = parsed
            this.untilDisplay = this.formatDate(this.until)
        },
        applyPreset(key) {
            this.from = this.presets[key].from
            this.until = this.presets[key].until
            this.fromDisplay = this.formatDate(this.from)
            this.untilDisplay = this.formatDate(this.until)
        },
        isPresetActive(key) {
            return this.from === this.presets[key].from && this.until === this.presets[key].until
        },
        selectedPeriodLabel() {
            const key = Object.keys(this.presets).find((key) => this.isPresetActive(key))

            return key ? this.presetLabels[key] : @js(__('lab.periods.custom'))
        },
        openCalendar(field) {
            const selectingExistingRangeEnd = field === 'until' && Boolean(this.from)
            this.activeDateField = selectingExistingRangeEnd ? 'until' : 'from'
            this.rangeDraftStart = selectingExistingRangeEnd ? this.from : null
            this.rangeDraftEnd = null
            const selected = this.dateParts(field === 'from' ? this.from : this.until)
            const fallback = this.dateParts(this.from) ?? this.dateParts(this.until)
            const focus = selected ?? fallback

            if (focus) {
                this.calendarYear = focus.year
                this.calendarMonth = focus.month - 1
            }

            this.calendarOpen = true
        },
        dateParts(value) {
            const match = String(value ?? '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/)
            if (! match) return null

            return { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) }
        },
        isoDate(year, month, day) {
            return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
        },
        calendarDays() {
            const first = new Date(this.calendarYear, this.calendarMonth, 1)
            const mondayOffset = (first.getDay() + 6) % 7
            const gridStart = new Date(this.calendarYear, this.calendarMonth, 1 - mondayOffset)

            return Array.from({ length: 42 }, (_, index) => {
                const date = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + index)
                return {
                    day: date.getDate(),
                    iso: this.isoDate(date.getFullYear(), date.getMonth() + 1, date.getDate()),
                    outside: date.getMonth() !== this.calendarMonth,
                }
            })
        },
        weekdayLabels() {
            const locale = document.documentElement.lang || navigator.language || 'ka'
            return Array.from({ length: 7 }, (_, index) => new Intl.DateTimeFormat(locale, { weekday: 'short' })
                .format(new Date(2024, 0, index + 1)))
        },
        monthLabel() {
            const locale = document.documentElement.lang || navigator.language || 'ka'
            return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' })
                .format(new Date(this.calendarYear, this.calendarMonth, 1))
        },
        moveMonth(offset) {
            const date = new Date(this.calendarYear, this.calendarMonth + offset, 1)
            this.calendarYear = date.getFullYear()
            this.calendarMonth = date.getMonth()
        },
        selectCalendarDate(value) {
            if (this.activeDateField === 'from') {
                this.rangeDraftStart = value
                this.rangeDraftEnd = null
                this.activeDateField = 'until'

                return
            }

            if (value < this.rangeDraftStart) {
                this.rangeDraftStart = value
                this.rangeDraftEnd = null
                this.activeDateField = 'until'

                return
            }

            this.rangeDraftEnd = value
            this.from = this.rangeDraftStart
            this.until = this.rangeDraftEnd
            this.calendarOpen = false
            this.activeDateField = 'from'
        },
        isToday(value) {
            const today = new Date()
            return value === this.isoDate(today.getFullYear(), today.getMonth() + 1, today.getDate())
        },
        visibleRangeStart() { return this.calendarOpen && this.rangeDraftStart ? this.rangeDraftStart : this.from },
        visibleRangeEnd() { return this.calendarOpen && this.rangeDraftStart ? this.rangeDraftEnd : this.until },
        isRangeStart(value) { return value === this.visibleRangeStart() },
        isRangeEnd(value) { return value === this.visibleRangeEnd() },
        isRangeMiddle(value) {
            const start = this.visibleRangeStart()
            const end = this.visibleRangeEnd()

            return start && end && value > start && value < end
        },
    }"
>
    {{ $this->createAction }}

    <div class="renome-visits-toolbar__period" x-on:click.outside="calendarOpen = false">
        <label class="renome-visits-toolbar__date" x-on:click="openCalendar('from')">
            <span class="fi-sr-only">{{ __('employees.salary.from') }}</span>
            <input
                type="text"
                x-model="fromDisplay"
                x-on:change="updateFrom()"
                x-on:blur="updateFrom()"
                x-on:keydown.enter.prevent.stop="openCalendar('from')"
                x-on:keydown.arrow-down.prevent.stop="openCalendar('from')"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="{{ __('employees.salary.from') }}"
            >
            <button
                type="button"
                class="renome-visits-toolbar__calendar"
                x-bind:aria-expanded="calendarOpen && activeDateField === 'from'"
                aria-label="{{ __('employees.salary.from') }}"
            >
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
            </button>
        </label>

        <span aria-hidden="true">—</span>

        <label class="renome-visits-toolbar__date" x-on:click="openCalendar('until')">
            <span class="fi-sr-only">{{ __('employees.salary.until') }}</span>
            <input
                type="text"
                x-model="untilDisplay"
                x-on:change="updateUntil()"
                x-on:blur="updateUntil()"
                x-on:keydown.enter.prevent.stop="openCalendar('until')"
                x-on:keydown.arrow-down.prevent.stop="openCalendar('until')"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="{{ __('employees.salary.until') }}"
            >
            <button
                type="button"
                class="renome-visits-toolbar__calendar"
                x-bind:aria-expanded="calendarOpen && activeDateField === 'until'"
                aria-label="{{ __('employees.salary.until') }}"
            >
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
            </button>
        </label>

        <div
            x-cloak
            x-show="calendarOpen"
            x-on:keydown.escape.window="calendarOpen = false"
            class="renome-date-range-calendar"
            role="dialog"
            aria-label="{{ __('employees.salary.period') }}"
        >
            <div class="renome-date-range-calendar__header">
                <button type="button" x-on:click="moveMonth(-1)" aria-label="{{ __('lab.previous_month') }}">
                    <x-filament::icon icon="heroicon-m-chevron-left" aria-hidden="true" />
                </button>
                <div class="renome-date-range-calendar__title" x-text="monthLabel()"></div>
                <button type="button" x-on:click="moveMonth(1)" aria-label="{{ __('lab.next_month') }}">
                    <x-filament::icon icon="heroicon-m-chevron-right" aria-hidden="true" />
                </button>
            </div>

            <div class="renome-date-range-calendar__weekdays">
                <template x-for="label in weekdayLabels()" :key="label">
                    <span x-text="label"></span>
                </template>
            </div>

            <div class="renome-date-range-calendar__days" role="grid">
                <template x-for="date in calendarDays()" :key="date.iso">
                    <button
                        type="button"
                        x-text="date.day"
                        x-on:click="selectCalendarDate(date.iso)"
                        x-bind:class="{
                            'is-outside': date.outside,
                            'is-today': isToday(date.iso),
                            'is-range-start': isRangeStart(date.iso),
                            'is-range-middle': isRangeMiddle(date.iso),
                            'is-range-end': isRangeEnd(date.iso),
                        }"
                        x-bind:aria-selected="isRangeStart(date.iso) || isRangeEnd(date.iso)"
                        role="gridcell"
                    ></button>
                </template>
            </div>
        </div>
    </div>

    <div class="renome-visits-toolbar__presets" aria-label="{{ __('employees.salary.period') }}">
        <div class="renome-visits-toolbar__period-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
            <button
                type="button"
                class="renome-visits-toolbar__preset renome-visits-toolbar__period-trigger"
                x-on:click="open = ! open"
                x-bind:class="{ 'is-active': Object.keys(presets).some((key) => isPresetActive(key)) }"
                x-bind:aria-expanded="open"
            >
                <span x-text="selectedPeriodLabel()">{{ __('lab.periods.custom') }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
            </button>

            <div class="renome-visits-toolbar__period-menu" x-show="open" x-cloak>
                @foreach ($periodLabels as $key => $label)
                    <button
                        type="button"
                        x-on:click="applyPreset('{{ $key }}'); open = false"
                        x-bind:class="{ 'is-active': isPresetActive('{{ $key }}') }"
                    >
                        {{ $label }}
                    </button>
                @endforeach
                <button type="button" x-on:click="openCalendar('from'); open = false">{{ __('lab.periods.custom') }}</button>
            </div>
        </div>
    </div>

    <label class="renome-visits-toolbar__doctor">
        <span class="fi-sr-only">{{ __('lab.source') }}</span>
        <select wire:model.live="tableFilters.toolbar.source" aria-label="{{ __('lab.source') }}">
            <option value="all">{{ __('lab.all') }}</option>
            @foreach (array_keys(\App\Models\LabCase::SOURCES) as $source)
                <option value="{{ $source }}">{{ __('lab.sources.'.$source) }}</option>
            @endforeach
        </select>
    </label>

    <label class="renome-visits-toolbar__doctor">
        <span class="fi-sr-only">{{ __('lab.doctor') }}</span>
        <select wire:model.live="tableFilters.toolbar.doctor_id" aria-label="{{ __('lab.doctor') }}">
            <option value="">{{ __('lab.all_doctors') }}</option>

            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->getKey() }}">{{ $doctor->full_name }}</option>
            @endforeach
        </select>
    </label>

    <label class="renome-visits-toolbar__search">
        <span class="fi-sr-only">{{ __('lab.search_placeholder') }}</span>
        <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
        <input
            type="search"
            wire:model.live.debounce.400ms="tableSearch"
            placeholder="{{ __('lab.search_placeholder') }} პაციენტით, {{ __('lab.doctor') }}თ..."
            aria-label="{{ __('lab.search_placeholder') }} პაციენტით ან {{ __('lab.doctor') }}თ"
        >
    </label>

</div>
