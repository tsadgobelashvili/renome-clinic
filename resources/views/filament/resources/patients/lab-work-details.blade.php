@php
    $case = $getRecord();
    $label = fn ($value) => filled($value)
        ? (\Illuminate\Support\Facades\Lang::has('patient-profile.lab_work_types.'.$value)
            ? __('patient-profile.lab_work_types.'.$value) : $value)
        : '—';
    $quantity = fn ($value) => filled($value) ? (string) (int) $value : '—';
    $lines = $case->mainWorks->map(fn ($work) => [
        'work' => $label($work->material), 'shade' => $work->shade ?: '—',
        'quantity' => $quantity($work->quantity), 'technician' => $work->technicianEmployee?->full_name ?: '—',
    ]);
    if ($lines->isEmpty()) {
        $lines->push(['work' => $label($case->material), 'shade' => $case->shade ?: '—',
            'quantity' => $quantity($case->quantity), 'technician' => '—']);
    }
    foreach ($case->additionalWorks as $work) {
        $lines->push(['work' => $label($work->work_type), 'quantity' => $quantity($work->quantity),
            'technician' => $work->technicianEmployee?->full_name ?: ($work->technician ?: '—')]);
    }
    foreach ($case->workItems as $work) {
        $lines->push(['work' => $label($work->work_type), 'quantity' => $quantity($work->quantity),
            'technician' => $work->technician?->name ?: '—']);
    }
    // Only explicit case-level performer records; never salary settings or default assignments.
    if ($case->modeled_by || filled($case->modeling)) {
        $lines->push(['work' => __('lab.modeling'), 'quantity' => '—',
            'technician' => $case->modeler?->name ?: ($case->modeling ?: '—')]);
    }
    if (filled($case->milling_quantity) && ! $case->additionalWorks->contains('work_type', 'milling')
        && ! $case->workItems->contains('work_type', 'milling')) {
        $lines->push(['work' => __('lab.milling'), 'quantity' => $quantity($case->milling_quantity),
            'technician' => $case->miller?->name ?: ($case->milling_technician ?: '—')]);
    }
@endphp
<div class="space-y-2 px-3 py-2 text-sm" style="min-width: 16rem; max-width: 34rem; white-space: normal; word-break: normal; overflow-wrap: break-word;">
    @foreach ($lines as $line)
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span><span class="text-xs text-gray-500">{{ array_key_exists('shade', $line) ? __('lab.material') : __('lab.work_type') }}:</span> {{ $line['work'] }}</span>
            @if (array_key_exists('shade', $line))
                <span><span class="text-xs text-gray-500">{{ __('lab.shade') }}:</span> {{ $line['shade'] }}</span>
            @endif
            <span class="whitespace-nowrap"><span class="text-xs text-gray-500">{{ __('lab.quantity') }}:</span> {{ $line['quantity'] }}</span>
            <span><span class="text-xs text-gray-500">{{ __('lab.technician') }}:</span> {{ $line['technician'] }}</span>
        </div>
    @endforeach
</div>
