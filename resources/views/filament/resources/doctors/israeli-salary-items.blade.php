<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs" aria-label="ლაბორატორიული სამუშაოები">
            <thead class="bg-gray-50 text-[11px] font-medium text-gray-500 dark:bg-white/5">
                <tr>
                    <th scope="col" class="w-10 px-2.5 py-2 text-center"><span class="sr-only">არჩევა</span></th>
                    <th scope="col" class="px-2.5 py-2 text-left">თარიღი</th>
                    <th scope="col" class="px-2.5 py-2 text-left">პაციენტი</th>
                    <th scope="col" class="px-2.5 py-2 text-left">სამუშაო</th>
                    <th scope="col" class="px-2.5 py-2 text-right">რაოდენობა</th>
                    <th scope="col" class="px-2.5 py-2 text-right">ხელფასი</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($rows as $row)
                    @php($item = $row['items'][0])
                    <tr wire:key="{{ $getId() }}-lab-{{ $item['id'] }}">
                        <td class="px-2.5 py-2 text-center">
                            <x-filament::input.checkbox
                                :value="(string) $item['id']"
                                :disabled="$isDisabled() || $isOptionDisabled($item['id'], $getOptions()[$item['id']] ?? '')"
                                :attributes="new \Illuminate\View\ComponentAttributeBag([
                                    $applyStateBindingModifiers('wire:model') => $getStatePath(),
                                    'aria-label' => $row['patient'].' — '.$item['name'],
                                ])"
                            />
                        </td>
                        <td class="whitespace-nowrap px-2.5 py-2">{{ $row['visit_date'] }}</td>
                        <td class="px-2.5 py-2 font-medium text-gray-950 dark:text-white">{{ $row['patient'] }}</td>
                        <td class="px-2.5 py-2">{{ $item['name'] }}</td>
                        <td class="px-2.5 py-2 text-right tabular-nums">{{ $item['quantity'] }}</td>
                        <td class="whitespace-nowrap px-2.5 py-2 text-right font-semibold tabular-nums">{{ \App\Support\Currency::format($row['doctor_share'], $row['currency']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-dynamic-component>
