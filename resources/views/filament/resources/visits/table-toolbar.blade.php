<div
    class="renome-visits-toolbar"
    aria-label="ვიზიტების ფილტრები"
    x-data="{
        from: $wire.entangle('tableFilters.visit_date.from', true),
        until: $wire.entangle('tableFilters.visit_date.until', true),
        presets: @js($datePresets),
        presetLabels: @js([
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
        init() {
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
    }"
>
    <x-filament::button
        :href="$createUrl"
        tag="a"
        color="primary"
        icon="heroicon-o-plus"
        icon-position="after"
        class="renome-visits-toolbar__create"
    >
        ახალი ვიზიტი
    </x-filament::button>

    <div class="renome-visits-toolbar__period">
        <label class="renome-visits-toolbar__date">
            <span class="fi-sr-only">თარიღიდან</span>
            <input
                type="text"
                x-model="fromDisplay"
                x-on:change="updateFrom()"
                x-on:blur="updateFrom()"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="თარიღიდან"
            >
            <span class="renome-visits-toolbar__calendar">
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
                <input
                    type="date"
                    x-model="from"
                    x-on:change="fromDisplay = formatDate(from)"
                    tabindex="-1"
                    aria-label="თარიღიდან კალენდრით არჩევა"
                >
            </span>
        </label>

        <span aria-hidden="true">—</span>

        <label class="renome-visits-toolbar__date">
            <span class="fi-sr-only">თარიღამდე</span>
            <input
                type="text"
                x-model="untilDisplay"
                x-on:change="updateUntil()"
                x-on:blur="updateUntil()"
                inputmode="numeric"
                placeholder="DD.MM.YYYY"
                aria-label="თარიღამდე"
            >
            <span class="renome-visits-toolbar__calendar">
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
                <input
                    type="date"
                    x-model="until"
                    x-on:change="untilDisplay = formatDate(until)"
                    tabindex="-1"
                    aria-label="თარიღამდე კალენდრით არჩევა"
                >
            </span>
        </label>
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
