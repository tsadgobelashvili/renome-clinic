        <section
            class="renome-visits-toolbar" style="flex-wrap: wrap"
            x-data="{
                from: $wire.entangle('tableFilters.review.from', true), until: $wire.entangle('tableFilters.review.until', true),
                fromDisplay: '', untilDisplay: '',
                init() { this.syncDisplay(); this.$watch('from', () => this.syncDisplay()); this.$watch('until', () => this.syncDisplay()); },
                syncDisplay() { this.fromDisplay = this.format(this.from); this.untilDisplay = this.format(this.until); },
                format(value) { if (!value) return ''; const [y,m,d] = String(value).slice(0,10).split('-'); return `${d}.${m}.${y}`; },
                parse(value) { const match = String(value).trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/); if (!match) return null; const iso = `${match[3]}-${match[2]}-${match[1]}`; const date = new Date(`${iso}T00:00:00`); return date.getFullYear() === Number(match[3]) && date.getMonth() + 1 === Number(match[2]) && date.getDate() === Number(match[1]) ? iso : null; },
                update(field) { const display = field === 'from' ? this.fromDisplay : this.untilDisplay; const parsed = this.parse(display); if (parsed || !display) this[field] = parsed || ''; this[field + 'Display'] = this.format(this[field]); },
                pick(field) { const picker = this.$refs[field + 'Picker']; if (picker.showPicker) picker.showPicker(); else picker.click(); },
                picked(field, value) { this[field] = value || ''; this[field + 'Display'] = this.format(value); }
            }"
        >
            <div class="renome-visits-toolbar__period" style="order: 0; flex: 0 1 auto">
                <label class="renome-visits-toolbar__date">
                    <span class="fi-sr-only">დან</span>
                    <input type="text" x-model="fromDisplay" x-on:change="update('from')" x-on:blur="update('from')" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="დან">
                    <button type="button" class="renome-visits-toolbar__calendar" x-on:click="pick('from')" aria-label="კალენდრის გახსნა"><x-filament::icon icon="heroicon-m-calendar-days" /></button>
                    <input x-ref="fromPicker" type="date" class="sr-only" tabindex="-1" x-bind:value="from" x-on:change="picked('from', $event.target.value)">
                </label>
                <span aria-hidden="true">—</span>
                <label class="renome-visits-toolbar__date">
                    <span class="fi-sr-only">მდე</span>
                    <input type="text" x-model="untilDisplay" x-on:change="update('until')" x-on:blur="update('until')" inputmode="numeric" placeholder="DD.MM.YYYY" aria-label="მდე">
                    <button type="button" class="renome-visits-toolbar__calendar" x-on:click="pick('until')" aria-label="კალენდრის გახსნა"><x-filament::icon icon="heroicon-m-calendar-days" /></button>
                    <input x-ref="untilPicker" type="date" class="sr-only" tabindex="-1" x-bind:value="until" x-on:change="picked('until', $event.target.value)">
                </label>
            </div>
            @php
                $fields = $this->getTableFiltersForm()->getComponentByStatePath('review')->getChildSchema()->getFlatFields();
            @endphp
            @foreach (['clinic', 'doctor', 'patient', 'technician', 'material'] as $name)
                <div wire:key="external-orders-filter-{{ $name }}" style="flex: 1 1 9rem; min-width: 0">
                    {{ $fields[$name] }}
                </div>
            @endforeach
            <button type="button" wire:click="removeTableFilters" class="ml-auto shrink-0 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                {{ app()->getLocale() === 'en' ? 'Clear' : 'გასუფთავება' }}
            </button>
        </section>