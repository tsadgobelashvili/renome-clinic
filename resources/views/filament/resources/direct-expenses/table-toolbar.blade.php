<div
    class="renome-visits-toolbar renome-direct-expenses-toolbar"
    aria-label="პირდაპირი ხარჯების ფილტრები"
    x-data="{
        from: $wire.entangle('tableFilters.visit_date.from', true),
        until: $wire.entangle('tableFilters.visit_date.until', true),
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
    }"
>
    <div class="renome-visits-toolbar__period" aria-label="პერიოდი">
        <label class="renome-visits-toolbar__date">
            <span class="fi-sr-only">პერიოდი: დან</span>
            <input type="text" x-model="fromDisplay" x-on:change="updateFrom()" x-on:blur="updateFrom()" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="პერიოდი: დან">
            <span class="renome-visits-toolbar__calendar">
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
                <input type="date" x-model="from" x-on:change="fromDisplay = formatDate(from)" tabindex="-1" aria-label="პერიოდი: დან, კალენდრით არჩევა">
            </span>
        </label>

        <span aria-hidden="true">—</span>

        <label class="renome-visits-toolbar__date">
            <span class="fi-sr-only">პერიოდი: მდე</span>
            <input type="text" x-model="untilDisplay" x-on:change="updateUntil()" x-on:blur="updateUntil()" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="პერიოდი: მდე">
            <span class="renome-visits-toolbar__calendar">
                <x-filament::icon icon="heroicon-m-calendar-days" aria-hidden="true" />
                <input type="date" x-model="until" x-on:change="untilDisplay = formatDate(until)" tabindex="-1" aria-label="პერიოდი: მდე, კალენდრით არჩევა">
            </span>
        </label>
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

    <label class="renome-visits-toolbar__doctor renome-direct-expenses-toolbar__status">
        <span class="fi-sr-only">ხარჯი შევსებულია</span>
        <select wire:model.live="tableFilters.expense_status.value" aria-label="ხარჯი შევსებულია">
            <option value="">ყველა</option>
            <option value="1">შევსებული</option>
            <option value="0">არ არის შევსებული</option>
        </select>
    </label>

    <label class="renome-visits-toolbar__search">
        <span class="fi-sr-only">ძიება</span>
        <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
        <input type="search" wire:model.live.debounce.400ms="tableSearch" placeholder="ძიება..." aria-label="ძიება">
    </label>
</div>
