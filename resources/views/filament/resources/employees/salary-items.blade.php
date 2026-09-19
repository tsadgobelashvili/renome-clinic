<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
        $selected = (array) ($getState() ?? []);
        $types = \App\Models\EmployeeSalaryRate::workTypes();
    @endphp
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                <th class="w-8"><span class="sr-only">{{ __('employees.salary.select') }}</span></th>
                <th>{{ __('employees.salary.date') }}</th><th>{{ __('lab.patient') }}</th>
                <th>{{ __('employees.salary.review_work') }}</th>
                <th class="text-right">{{ __('employees.salary.total') }}</th><th class="w-8"></th>
            </tr></thead>
            @forelse ($groups as $key => $group)
                @php
                    $itemKeys = array_keys($group['items']);
                    $selectedCount = count(array_intersect($itemKeys, $selected));
                    $open = $expandedGroup === $key;
                @endphp
                <tbody wire:key="salary-review-{{ $key }}">
                    <tr data-salary-group="{{ $key }}">
                        <td class="text-center">
                            <x-filament::input.checkbox :checked="$selectedCount === count($itemKeys)" :disabled="$isDisabled()"
                                wire:key="salary-review-selection-{{ $key }}-{{ $selectedCount }}"
                                wire:click="toggleSalaryReviewSelection('{{ $key }}')" wire:loading.attr="disabled"
                                x-data x-init="$el.indeterminate = @js($selectedCount > 0 && $selectedCount < count($itemKeys))"
                                aria-label="{{ __('employees.salary.select').' — '.$group['patient'].' — '.$group['date'] }}" />
                        </td>
                        <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($group['date'])->format('d.m.Y') }}</td>
                        <td class="font-semibold">{{ $group['patient'] }} <span class="font-normal text-gray-500">#{{ $group['case_id'] }}</span></td>
                        <td class="whitespace-nowrap text-gray-500">{{ __('employees.salary.review_count', ['count' => count($itemKeys)]) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ number_format($group['total_cents'] / 100, 2) }} GEL</td>
                        <td>
                            <button type="button" wire:click="toggleSalaryReviewGroup('{{ $key }}')" class="p-1 text-gray-500"
                                aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="salary-details-{{ $key }}"
                                aria-label="{{ __('employees.salary.review_work').' — '.$group['patient'] }}">
                                <x-filament::icon :icon="$open ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'" class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                    @if ($open)
                        @php
                            $detailPages = max(1, (int) ceil(count($itemKeys) / \App\Support\TechnicianSalaryReview::ITEMS_PER_PAGE));
                            $currentDetailPage = min(max(1, $detailPage), $detailPages);
                        @endphp
                        <tr id="salary-details-{{ $key }}"><td colspan="6" class="bg-gray-50 dark:bg-white/5">
                            <div class="border-l border-gray-200 pl-3 dark:border-white/10">
                                @foreach (collect($group['items'])->forPage($currentDetailPage, \App\Support\TechnicianSalaryReview::ITEMS_PER_PAGE) as $itemKey => $row)
                                    <label wire:key="employee-salary-{{ $itemKey }}" data-salary-item="{{ $itemKey }}" class="flex items-center gap-2 py-1">
                                        <x-filament::input.checkbox :value="$itemKey" :disabled="$isDisabled()"
                                            :attributes="new \Illuminate\View\ComponentAttributeBag([$applyStateBindingModifiers('wire:model') => $getStatePath(), 'aria-label' => $row['patient_name'].' — '.($types[$row['work_type']] ?? $row['work_type'])])" />
                                        <span>{{ $types[$row['work_type']] ?? $row['work_type'] }}</span>
                                        <span class="ml-auto text-right tabular-nums">{{ $row['rate_basis'] === 'per_work' ? 1 : $row['quantity'] }} × {{ number_format($row['rate_amount'], 2) }} <span class="text-gray-500">/ {{ __('employees.salary.'.$row['rate_basis']) }}</span> = <strong>{{ number_format($row['amount_gel'], 2) }} GEL</strong></span>
                                    </label>
                                @endforeach
                                @if ($detailPages > 1)
                                    <div class="flex items-center justify-end gap-2 py-1">
                                        <x-filament::button size="xs" color="gray" wire:click="setSalaryReviewDetailPage({{ $currentDetailPage - 1 }})" :disabled="$currentDetailPage === 1">{{ __('employees.salary.review_previous') }}</x-filament::button>
                                        <span>{{ $currentDetailPage }} / {{ $detailPages }}</span>
                                        <x-filament::button size="xs" color="gray" wire:click="setSalaryReviewDetailPage({{ $currentDetailPage + 1 }})" :disabled="$currentDetailPage === $detailPages">{{ __('employees.salary.review_next') }}</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        </td></tr>
                    @endif
                </tbody>
            @empty
                <tbody><tr><td colspan="6" class="text-center text-gray-500">{{ __('employees.salary.no_pending') }}</td></tr></tbody>
            @endforelse
        </table>
        @if ($pages > 1)
            <div class="flex items-center justify-end gap-2 px-3 py-2 text-xs">
                <span class="mr-auto text-gray-500">{{ __('employees.salary.review_groups', ['count' => $groupCount]) }}</span>
                <x-filament::button size="xs" color="gray" wire:click="setSalaryReviewPage({{ $page - 1 }})" :disabled="$page === 1">{{ __('employees.salary.review_previous') }}</x-filament::button>
                <span>{{ $page }} / {{ $pages }}</span>
                <x-filament::button size="xs" color="gray" wire:click="setSalaryReviewPage({{ $page + 1 }})" :disabled="$page === $pages">{{ __('employees.salary.review_next') }}</x-filament::button>
            </div>
        @endif
    </div>
</x-dynamic-component>
