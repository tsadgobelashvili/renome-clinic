<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10" data-manual-case-salary>
        <table class="w-full text-sm">
            <thead><tr>
                <th class="px-2 py-2"><span class="sr-only">{{ __('manual-lab-salary.select') }}</span></th>
                <th class="px-2 py-2 text-left">{{ __('israeli-payout-history.date') }}</th>
                <th class="px-2 py-2 text-left">{{ __('israeli-payout-history.patient') }}</th>
                <th class="px-2 py-2 text-left">{{ __('manual-lab-salary.works') }}</th>
                <th class="px-2 py-2 text-left">{{ __('manual-lab-salary.amount') }}</th>
            </tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr wire:key="{{ $getId() }}-manual-case-{{ $row['manual_case_id'] }}">
                        <td class="px-2 py-2"><x-filament::input.checkbox :value="(string) $row['manual_case_id']" :disabled="! $canEdit"
                            :attributes="new \Illuminate\View\ComponentAttributeBag([$applyStateBindingModifiers('wire:model') => $getStatePath(), 'aria-label' => $row['patient']])" /></td>
                        <td class="px-2 py-2">{{ $row['visit_date'] }}<small class="block text-gray-500">Lab #{{ $row['manual_case_id'] }}</small></td>
                        <td class="px-2 py-2">{{ $row['patient'] }}</td>
                        <td class="px-2 py-2">{{ $row['manipulations'] }}</td>
                        <td class="px-2 py-2">
                            @if($canEdit)
                                <x-filament::input.wrapper suffix="GEL">
                                    <x-filament::input type="number" min="0" max="99999999999.99" step="0.01"
                                        :attributes="new \Illuminate\View\ComponentAttributeBag([
                                            'wire:model.live.debounce.200ms' => \Illuminate\Support\Str::beforeLast($getStatePath(), '.').'.manual_case_amounts.'.$row['manual_case_id'],
                                            'aria-label' => __('manual-lab-salary.amount').' — '.$row['patient'],
                                        ])" />
                                </x-filament::input.wrapper>
                            @else
                                <small class="text-gray-500">{{ __('manual-lab-salary.owner_only') }}</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-3 text-gray-500">{{ __('manual-lab-salary.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="px-2 pb-2 text-xs text-gray-500">{{ __('manual-lab-salary.help') }}</p>
    </div>
</x-dynamic-component>
