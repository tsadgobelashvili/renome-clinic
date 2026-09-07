<div
    class="renome-visits-toolbar"
    aria-label="ვიზიტების ფილტრები"
    x-data="{
        from: $wire.entangle('tableFilters.visit_date.from', true),
        until: $wire.entangle('tableFilters.visit_date.until', true),
        presets: @js($datePresets),
        presetLabels: @js([
            'today' => 'დღეს',
            '7' => '7 დღე',
            '14' => '14 დღე',
            'month' => '1 თვე',
            '3months' => '3 თვე',
            '6months' => '6 თვე',
            'year' => '1 წელი',
            'all' => 'ყველა პერიოდი',
        ]),
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

            return key ? this.presetLabels[key] : 'არჩეული პერიოდი'
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
    @if (str_contains($createUrl, 'return=dashboard'))
        <x-filament::button type="button" wire:click="mountAction('newVisit')" color="primary" icon="heroicon-o-plus" icon-position="after" class="renome-visits-toolbar__create">
            ახალი ვიზიტი
        </x-filament::button>
    @else
        <x-filament::button :href="$createUrl" tag="a" color="primary" icon="heroicon-o-plus" icon-position="after" class="renome-visits-toolbar__create">
            ახალი ვიზიტი
        </x-filament::button>
    @endif

    <div class="renome-visits-toolbar__period" x-on:click.outside="calendarOpen = false">
        <label class="renome-visits-toolbar__date" x-on:click="openCalendar('from')">
            <span class="fi-sr-only">თარიღიდან</span>
            <input
                type="text"
                x-model="fromDisplay"
                x-on:change="updateFrom()"
                x-on:blur="updateFrom()"
                x-on:keydown.enter.prevent.stop="openCalendar('from')"
                x-on:keydown.arrow-down.prevent.stop="openCalendar('from')"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="თარიღიდან"
            >
            <button
                type="button"
                class="renome-visits-toolbar__calendar"
                x-bind:aria-expanded="calendarOpen && activeDateField === 'from'"
                aria-label="თარიღიდან კალენდრით არჩევა"
            >
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
            </button>
        </label>

        <span aria-hidden="true">—</span>

        <label class="renome-visits-toolbar__date" x-on:click="openCalendar('until')">
            <span class="fi-sr-only">თარიღამდე</span>
            <input
                type="text"
                x-model="untilDisplay"
                x-on:change="updateUntil()"
                x-on:blur="updateUntil()"
                x-on:keydown.enter.prevent.stop="openCalendar('until')"
                x-on:keydown.arrow-down.prevent.stop="openCalendar('until')"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="თარიღამდე"
            >
            <button
                type="button"
                class="renome-visits-toolbar__calendar"
                x-bind:aria-expanded="calendarOpen && activeDateField === 'until'"
                aria-label="თარიღამდე კალენდრით არჩევა"
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
            aria-label="თარიღის არჩევა"
        >
            <div class="renome-date-range-calendar__header">
                <button type="button" x-on:click="moveMonth(-1)" aria-label="წინა თვე">
                    <x-filament::icon icon="heroicon-m-chevron-left" aria-hidden="true" />
                </button>
                <div class="renome-date-range-calendar__title" x-text="monthLabel()"></div>
                <button type="button" x-on:click="moveMonth(1)" aria-label="შემდეგი თვე">
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

    <div class="renome-visits-toolbar__presets" aria-label="სწრაფი პერიოდის არჩევა">
        <div class="renome-visits-toolbar__period-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
            <button
                type="button"
                class="renome-visits-toolbar__preset renome-visits-toolbar__period-trigger"
                x-on:click="open = ! open"
                x-bind:class="{ 'is-active': Object.keys(presets).some((key) => isPresetActive(key)) }"
                x-bind:aria-expanded="open"
            >
                <span x-text="selectedPeriodLabel()">7 დღე</span>
                <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
            </button>

            <div class="renome-visits-toolbar__period-menu" x-show="open" x-cloak>
                @foreach (['7' => '7 დღე', '14' => '14 დღე', 'month' => '1 თვე', '3months' => '3 თვე', '6months' => '6 თვე', 'year' => '1 წელი', 'all' => 'ყველა პერიოდი'] as $key => $label)
                    <button
                        type="button"
                        x-on:click="applyPreset('{{ $key }}'); open = false"
                        x-bind:class="{ 'is-active': isPresetActive('{{ $key }}') }"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <label class="renome-visits-toolbar__doctor">
        <span class="fi-sr-only">ექიმი</span>
        <select wire:model.live="tableFilters.doctor_id.value" aria-label="ექიმი">
            <option value="">ყველა ექიმი</option>

            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->getKey() }}">{{ $doctor->full_name }}</option>
            @endforeach
        </select>
    </label>

    <label class="renome-visits-toolbar__search">
        <span class="fi-sr-only">ძიება</span>
        <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
        <input
            type="search"
            wire:model.live.debounce.400ms="tableSearch"
            placeholder="ძიება პაციენტით, ექიმით..."
            aria-label="ძიება პაციენტით ან ექიმით"
        >
    </label>

</div>
