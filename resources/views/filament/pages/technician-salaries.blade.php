<x-filament-panels::page>
    <div wire:init="$set('ready', true)">
        <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
            <label>{{ __('employees.salary.from') }} <input type="date" wire:model.live="from" class="rounded-lg border-gray-200 text-sm"></label>
            <label>{{ __('employees.salary.until') }} <input type="date" wire:model.live="until" class="rounded-lg border-gray-200 text-sm"></label>
            <span class="text-xs text-gray-500">{{ __('employees.salary.overview_period') }}</span>
        </div>
        @error('from') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
        @error('until') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
        @php($overview = $this->overview())
        @if ($overview)
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5"><tr>
                        <th class="px-3 py-2 text-left">{{ __('lab.technician') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('employees.salary.total_due') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('employees.salary.last_finalized') }}</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($overview['records'] as $technician)
                        <tr wire:key="technician-salary-{{ $technician->id }}"
                            @if ($technician->salary_active && $technician->salary_type)
                                wire:click="openSalary({{ $technician->id }})"
                                wire:keydown.enter.self.prevent="openSalary({{ $technician->id }})"
                                wire:keydown.space.self.prevent="openSalary({{ $technician->id }})"
                                tabindex="0"
                                aria-label="{{ $technician->full_name }} — {{ __('employees.salary.open_review') }}"
                            @endif
                            @class(['even:bg-gray-50 dark:even:bg-white/5', 'cursor-pointer hover:bg-gray-100 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-teal-600' => $technician->salary_active && $technician->salary_type])>
                            <td class="px-3 py-2">{{ $technician->full_name }}
                                <span class="text-xs text-gray-500">{{ $technician->salary_type === 'fixed' ? $overview['month'] : '' }}</span>
                                @if (! $technician->salary_active || ! $technician->salary_type)<span class="text-xs text-gray-500">{{ __('employees.salary.unavailable') }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ number_format($overview['totals'][$technician->id], 2) }} GEL</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $technician->last_finalized ? \Carbon\Carbon::parse($technician->last_finalized)->format('d.m.Y H:i') : '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($technician->salary_active && $technician->salary_type)
                                    <x-filament::button size="xs" wire:click.stop="openSalary({{ $technician->id }})">{{ __('employees.salary.open_review') }}</x-filament::button>
                                @endif
                                <x-filament::button size="xs" color="gray" wire:click.stop="openHistory({{ $technician->id }})">{{ __('employees.salary.history') }}</x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-2 text-gray-500">{{ __('employees.salary.no_pending') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $overview['records']->links() }}</div>
        @else
            <div class="text-sm text-gray-500" role="status">{{ $ready ? __('employees.salary.select_period') : __('employees.salary.loading') }}</div>
        @endif
    </div>
</x-filament-panels::page>
