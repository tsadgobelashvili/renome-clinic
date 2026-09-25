@php
    $visit = $getRecord();
    $summary = $getState();
@endphp
<div class="renome-visit-badges" wire:click="mountTableAction('visitDetails', '{{ $visit->getKey() }}')">
    @if ($summary !== '—')
        <span class="renome-visit-badges__procedures">{{ $formatState($summary) }}</span>
    @endif
    @if ($visit->visit_type === 'consultation' && ! str_contains($summary, 'renome-treatment-consultation'))
        <span class="renome-treatment-service renome-treatment-consultation">{{ app()->getLocale() === 'en' ? 'Consultation' : 'კონსულტაცია' }}</span>
    @elseif ($summary === '—')
        <span>—</span>
    @endif
    @foreach (($visit->patient?->treatmentEstimates ?? collect())->sortByDesc('estimate_date') as $estimate)
        @if ($estimate->patient_id === $visit->patient_id && auth()->user()->can('view', $estimate))
            <x-filament::button
                class="renome-visit-plan-chip"
                type="button"
                size="xs"
                color="gray"
                outlined
                wire:click.stop="mountTableAction('treatmentPlan', '{{ $visit->getKey() }}', { estimate: {{ $estimate->getKey() }} })"
            >
                გეგმა @if ($visit->patient->treatmentEstimates->count() > 1) · {{ $estimate->estimate_date?->format('d.m.Y') }} @endif
            </x-filament::button>
        @endif
    @endforeach
</div>
