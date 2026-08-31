<div class="space-y-4">
    <div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">პაციენტი</div>
            <div class="font-semibold text-gray-950 dark:text-white">{{ $patient->full_name }}</div>
        </div>
    </div>

    @forelse ($patient->treatmentEstimates as $estimate)
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-3 dark:border-white/10">
                <div>
                    <h3 class="font-semibold text-gray-950 dark:text-white">
                        მკურნალობის გეგმა — {{ $estimate->estimate_date->format('d.m.Y') }}
                    </h3>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        ექიმი: {{ $estimate->doctor?->full_name ?: '—' }}
                    </div>
                    @if (filled($estimate->comment))
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $estimate->comment }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        :href="route('treatment-estimates.pdf', ['patient' => $patient, 'estimate' => $estimate])"
                        tag="a"
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrow-down-tray"
                    >
                        PDF
                    </x-filament::button>
                    <x-filament::button
                        :href="route('treatment-estimates.word', ['patient' => $patient, 'estimate' => $estimate])"
                        tag="a"
                        size="xs"
                        color="gray"
                        icon="heroicon-m-document-text"
                    >
                        Word
                    </x-filament::button>
                </div>
            </div>

            <div class="mt-3 space-y-4">
                @forelse ($estimate->options as $optionIndex => $option)
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        @if ($estimate->options->count() > 1)
                            <div class="mb-3 font-medium text-primary-700 dark:text-primary-300">
                                {{ $option->name ?: 'ვარიანტი '.($optionIndex + 1) }}
                            </div>
                        @endif

                        @foreach ($option->stages as $stage)
                            @if ($option->stages->count() > 1)
                                <div class="mb-2 mt-3 text-sm font-medium text-gray-700 first:mt-0 dark:text-gray-200">
                                    {{ $stage->name }}
                                </div>
                            @endif

                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full min-w-[34rem] text-sm">
                                    <thead class="bg-white text-left text-xs font-semibold text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                        <tr>
                                            <th class="px-3 py-2">მანიპულაცია</th>
                                            <th class="px-3 py-2 text-right">რაოდენობა</th>
                                            <th class="px-3 py-2 text-right">ერთეულის ფასი</th>
                                            <th class="px-3 py-2 text-right">ჯამი</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                        @forelse ($stage->items as $item)
                                            <tr>
                                                <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $item->description }}</td>
                                                <td class="px-3 py-2 text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                                                <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $item->unit_price, 2) }} ₾</td>
                                                <td class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ number_format($item->line_total, 2) }} ₾</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="px-3 py-3 text-center text-gray-500">მანიპულაციები ჯერ არ არის.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endforeach

                        <div class="mt-3 flex flex-wrap items-end justify-between gap-2">
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                @if (filled($option->estimated_duration))
                                    სავარაუდო დრო: {{ $option->estimated_duration }}
                                @endif
                                @if (filled($option->comment))
                                    <div class="mt-1">{{ $option->comment }}</div>
                                @endif
                            </div>
                            <div class="text-right">
                                @if ($option->discount_amount > 0)
                                    <div class="text-xs text-gray-500">ფასდაკლება: {{ $option->discount_display }}</div>
                                @endif
                                <div class="font-semibold text-gray-950 dark:text-white">
                                    სულ: {{ number_format($option->final_amount, 2) }} ₾
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500 dark:border-white/20">
                        ამ გეგმაში ვარიანტები ჯერ არ არის.
                    </div>
                @endforelse
            </div>
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-white/20">
            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="mx-auto mb-3 h-8 w-8 text-gray-400" />
            <p class="text-sm text-gray-600 dark:text-gray-300">
                ამ პაციენტისთვის მკურნალობის გეგმა ჯერ არ არის შექმნილი.
            </p>
        </div>
    @endforelse
</div>
