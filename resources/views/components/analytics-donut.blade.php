@props(['rows', 'currency', 'total' => null, 'title' => '', 'chartKey' => 'donut', 'size' => '9rem'])
<div class="renome-donut" style="--donut-size: {{ $size }}"
    wire:key="{{ $chartKey }}-{{ md5(json_encode([$rows, $currency, $total])) }}"
    x-data="{
        rows: @js(array_values(is_array($rows) ? $rows : $rows->all())), currency: @js($currency), suppliedTotal: @js($total), active: null,
        colors: ['#65a99a', '#739bc4', '#a492bd', '#d1ae6f', '#c88f98', '#8b9ba9'],
        get sum() { return this.rows.reduce((sum, row) => sum + Number(row.amount || 0), 0); },
        get selected() { return this.active === null ? null : this.rows[this.active]; },
        color(i) { return this.colors[i % this.colors.length]; },
        money(value) { return new Intl.NumberFormat(document.documentElement.lang || 'en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0)) + ' ' + ({ GEL: '₾', USD: '$', EUR: '€' }[this.currency] || this.currency); },
        percent(row) { const value = Number(row.percentage ?? (this.sum ? Number(row.amount) / this.sum * 100 : 0)); return (value > 0 && value < 0.01 ? '&lt;0.01' : value.toFixed(value > 0 && value < 0.1 ? 2 : 1)) + '%'; },
        point(radius, angle) { return [100 + radius * Math.sin(angle), 100 - radius * Math.cos(angle)]; },
        arc(i) {
            if (!this.sum || Number(this.rows[i].amount) <= 0) return '';
            const start = this.rows.slice(0, i).reduce((sum, row) => sum + Number(row.amount || 0), 0) / this.sum * Math.PI * 2;
            const span = Number(this.rows[i].amount) / this.sum * Math.PI * 2;
            const end = start + Math.min(span, Math.PI * 2 - 0.00001);
            const a = this.point(87, start), b = this.point(87, end), c = this.point(49, end), d = this.point(49, start);
            const large = span > Math.PI ? 1 : 0;
            return `M ${a} A 87 87 0 ${large} 1 ${b} L ${c} A 49 49 0 ${large} 0 ${d} Z`;
        },
        emphasis(i) {
            if (this.active !== i || !this.sum) return '';
            const before = this.rows.slice(0, i).reduce((sum, row) => sum + Number(row.amount || 0), 0);
            const angle = (before + Number(this.rows[i].amount) / 2) / this.sum * Math.PI * 2;
            return `translate(${Math.sin(angle) * 3}px, ${-Math.cos(angle) * 3}px)`;
        }
    }" :class="{ 'is-empty': sum <= 0 }" x-on:pointerleave="active = null" x-on:keydown.escape="active = null">
    <div class="renome-donut__plot" x-show="sum > 0">
        <svg viewBox="0 0 200 200" role="group" aria-label="{{ $title }}">
            <circle cx="100" cy="100" r="68" fill="none" stroke="currentColor" stroke-width="38" class="renome-donut__empty" />
            @foreach(array_values(is_array($rows) ? $rows : $rows->all()) as $index => $row)
                <path :d="arc({{ $index }})" :fill="color({{ $index }})" :style="{ transform: emphasis({{ $index }}), opacity: active === null || active === {{ $index }} ? 1 : 0.68 }"
                    :class="{ 'is-active': active === {{ $index }} }"
                    class="renome-donut__slice" tabindex="0" role="img"
                    :aria-label="rows[{{ $index }}].label + ': ' + money(rows[{{ $index }}].amount) + ', ' + percent(rows[{{ $index }}])"
                    x-on:pointerenter="active = {{ $index }}" x-on:pointerleave="active = null" x-on:focus="active = {{ $index }}" x-on:blur="active = null" />
            @endforeach
        </svg>
        <strong class="renome-donut__total" x-text="money(suppliedTotal ?? sum)"></strong>
        <div x-cloak x-show="selected" class="renome-donut__tooltip" role="tooltip" aria-live="polite">
            <strong x-text="selected?.label"></strong>
            <span x-text="money(selected?.amount)"></span>
            <span x-text="selected ? percent(selected) : ''"></span>
        </div>
    </div>
    <div class="renome-donut__legend">
        @if($slot->isNotEmpty())
            {{ $slot }}
        @else
            <template x-for="(row, i) in rows" :key="i">
                <button type="button" class="renome-donut__legend-row" :class="{ 'is-active': active === i }"
                    x-on:pointerenter="active = i" x-on:pointerleave="active = null" x-on:focus="active = i" x-on:blur="active = null"
                    :aria-label="row.label + ': ' + money(row.amount) + ', ' + percent(row)">
                    <span class="renome-donut__swatch" :style="{ background: color(i) }"></span>
                    <span class="renome-donut__label" x-text="row.label"></span>
                    <strong x-text="money(row.amount)"></strong>
                    <span class="renome-donut__percent" x-text="percent(row)"></span>
                </button>
            </template>
        @endif
    </div>
</div>
