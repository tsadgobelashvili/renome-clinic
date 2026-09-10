<div class="border-t border-gray-100 pt-3 dark:border-white/10">
    <button
        type="button"
        wire:click="toggleDoctorSalaryHistory({{ $doctorId }})"
        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/10"
    >
        <x-filament::icon :icon="$historyVisible ? 'heroicon-m-chevron-up' : 'heroicon-m-clock'" class="size-4" />
        {{ __('salaries.history') }}
    </button>

    @if ($historyVisible)
        <div wire:key="doctor-salary-history-{{ $doctorId }}" class="mt-3 max-h-96 overflow-y-auto">
            @include('filament.resources.doctors.salary-history-records', ['settlements' => $settlements])
        </div>
    @endif
</div>
