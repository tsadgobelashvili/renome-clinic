<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
    <table class="renome-partner-treatment-history w-full text-left text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5">
            <tr>
                <th>{{ __('lab.date') }}</th><th>{{ __('lab.doctor') }}</th>
                <th>{{ __('lab.material') }}</th><th class="text-right">{{ __('lab.quantity') }}</th>
                <th>{{ __('lab.shade') }}</th><th>{{ __('lab.technician') }}</th>
            </tr>
        </thead>
        @forelse (($getState() ?? []) as $row)
            <tbody wire:key="patient-history-{{ $row['key'] }}" class="border-t border-gray-200 dark:border-white/10 {{ $loop->even ? 'bg-gray-50 dark:bg-white/5' : '' }}">
                @foreach (($row['details'] ?? [['material' => $row['work'], 'quantity' => '—', 'shade' => '—', 'technician' => '—']]) as $detail)
                    <tr>
                        <td class="whitespace-nowrap tabular-nums">
                            @if ($loop->first)
                                {{ $row['date']->format('d.m.Y') }}
                                @if ($row['type'] === 'lab')<span class="ml-1 rounded bg-gray-100 px-1 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10">LAB</span>@endif
                            @endif
                        </td>
                        <td>{{ $loop->first ? $row['doctor'] : '' }}</td>
                        <td class="font-medium">{{ $detail['material'] }}</td>
                        <td class="text-right tabular-nums">{{ $detail['quantity'] }}</td>
                        <td>{{ $detail['shade'] }}</td>
                        <td>{{ $detail['technician'] }}</td>
                    </tr>
                @endforeach
                @if (($row['additional'] ?? null) || ($row['notes'] ?? null))
                    <tr><td colspan="6" class="text-xs text-gray-500">
                        @if ($row['additional'])<div>{{ __('lab.additional_work') }}: {{ $row['additional'] }}</div>@endif
                        @if ($row['notes'])<div class="whitespace-pre-line break-words">{{ $row['notes'] }}</div>@endif
                    </td></tr>
                @endif
            </tbody>
        @empty
            <tbody><tr><td colspan="6" class="text-gray-500">{{ app()->getLocale() === 'ka' ? 'მკურნალობის ისტორია ჯერ არ არის.' : 'No treatment history yet.' }}</td></tr></tbody>
        @endforelse
    </table>
</div>
