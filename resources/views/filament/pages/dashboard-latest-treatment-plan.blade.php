@if ($estimate)
    @php
        $summary = $estimate->options->pluck('name')->filter()->first()
            ?? $estimate->comment
            ?? 'მკურნალობის გეგმა';
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
        <div class="min-w-0">
            <div class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $summary }}</div>
            <div class="mt-0.5 text-xs text-gray-500">
                {{ $estimate->estimate_date?->format('d.m.Y') }}
                @if ($estimate->doctor)
                    <span class="mx-1">·</span>{{ $estimate->doctor->full_name }}
                @endif
            </div>
        </div>
        <div class="flex items-center gap-2">
            <x-filament::button type="button" size="xs" color="gray" wire:click="editDashboardTreatmentPlan({{ $estimate->getKey() }})">
                გეგმის ნახვა
            </x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" wire:click="editDashboardTreatmentPlan({{ $estimate->getKey() }})">
                გეგმის რედაქტირება
            </x-filament::button>
        </div>
    </div>
@else
    <div class="rounded-lg border border-dashed border-gray-300 px-3 py-2 dark:border-white/15">
        <span class="text-sm text-gray-500">მკურნალობის გეგმა ჯერ არ არის.</span>
    </div>
@endif
