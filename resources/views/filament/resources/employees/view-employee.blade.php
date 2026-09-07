<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $record->full_name }}</x-slot>
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500">
            <span>{{ $record->position?->name }}</span>
            <span>{{ __('employees.active') }}: {{ $record->is_active ? __('employees.salary.yes') : __('employees.salary.no') }}</span>
            <span>{{ __('employees.phone') }}: {{ $record->phone ?: '—' }}</span>
            <span>{{ __('employees.birth_date') }}: {{ $record->birth_date?->format('d.m.Y') ?? '—' }}</span>
            <span>{{ __('employees.personal_id') }}: {{ $record->personal_id ?: '—' }}</span>
        </div>
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">{{ __('employees.performed_work') }}</x-slot>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>
                    <th>{{ __('lab.date') }}</th><th>{{ __('lab.patient') }}</th><th>{{ __('lab.work_type') }}</th>
                    <th class="text-right">{{ __('lab.qty') }}</th><th>{{ __('employees.work_role') }}</th><th>{{ __('lab.source') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->performedWorks() as $work)
                        <tr>
                            <td class="whitespace-nowrap">{{ $work['date']->format('d.m.Y') }}</td>
                            <td class="font-semibold">{{ $work['patient'] }}</td><td>{{ $work['work'] }}</td>
                            <td class="text-right tabular-nums">{{ $work['quantity'] }}</td><td>{{ $work['role'] }}</td>
                            <td>{{ __('lab.sources.'.$work['source']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-gray-500">{{ __('employees.no_performed_work') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
