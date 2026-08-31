<span class="inline-flex items-center gap-1 whitespace-nowrap">
    <span>ექიმი:</span>

    <select
        wire:model.live="tableFilters.doctor_id.value"
        aria-label="ექიმი"
        class="h-6 max-w-32 rounded-md border-0 bg-transparent py-0 ps-1 pe-6 text-xs font-normal text-gray-600 ring-1 ring-inset ring-gray-200 focus:ring-primary-500 dark:text-gray-300 dark:ring-white/10"
    >
        <option value="">ყველა</option>

        @foreach ($doctors as $doctorId => $doctorName)
            <option value="{{ $doctorId }}">{{ $doctorName }}</option>
        @endforeach
    </select>
</span>
