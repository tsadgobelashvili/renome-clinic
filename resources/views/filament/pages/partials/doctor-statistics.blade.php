<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">სულ პაციენტები</span>
        <div class="mt-1 text-xl font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($doctorStatistics['totalPatients']) }}</div>
    </section>
    <section class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">შემოსავალი</span>
        <div class="mt-1 text-xl font-bold tabular-nums text-emerald-500 dark:text-emerald-400">{{ \App\Support\Currency::format($doctorStatistics['totalRevenue'], $currency) }}</div>
    </section>
</div>

<section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">კატეგორიები</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] text-sm">
            <thead class="bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr><th class="px-4 py-2 text-left">კატეგორია</th><th class="px-3 py-2 text-right">პაციენტები</th><th class="px-3 py-2 text-right">სამუშაო</th><th class="px-3 py-2 text-right">შემოსავალი</th><th class="px-4 py-2 text-right">წილი</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($doctorStatistics['categories'] as $category)
                    <tr wire:key="doctor-category-{{ $category['key'] }}" class="even:bg-gray-50/60 dark:even:bg-white/[0.02]">
                        <td class="px-4 py-2 font-semibold text-gray-800 dark:text-gray-200">{{ $category['label'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($category['patients']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($category['procedures']) }}</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($category['revenue'], $currency) }}</td>
                        <td class="px-4 py-2 text-right text-xs font-semibold tabular-nums text-gray-500">{{ number_format($category['percentage'], 1) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">არჩეულ პერიოდში მონაცემები არ არის.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">ექიმების რეიტინგი</h2></div>
    <div class="divide-y divide-gray-100 dark:divide-gray-700">
        @forelse($doctorStatistics['doctors'] as $doctor)
            <div wire:key="doctor-stat-row-{{ $doctor['id'] }}">
                <button type="button" wire:click="toggleDoctor({{ $doctor['id'] }})" class="grid w-full grid-cols-1 gap-2 px-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03] sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="{{ $selectedDoctorId === $doctor['id'] ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right' }}" class="size-4 shrink-0 text-gray-400" />
                            <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $doctor['name'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $doctor['patients'] }} პაციენტი · {{ $doctor['procedures'] }} ვიზიტი/სამუშაო</span>
                        </div>
                        <div class="ml-6 mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-primary-500" style="width: {{ min(100, $doctor['percentage']) }}%"></div></div>
                    </div>
                    <div class="flex items-baseline gap-2 whitespace-nowrap text-right">
                        <strong class="text-sm tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($doctor['revenue'], $currency) }}</strong>
                        <span class="text-xs tabular-nums text-gray-500">{{ number_format($doctor['percentage'], 1) }}%</span>
                    </div>
                </button>

                @if($selectedDoctorId === $doctor['id'])
                    @php($detail = $doctorStatistics['details'][$doctor['id']])
                    <div wire:key="doctor-details-{{ $doctor['id'] }}" class="border-t border-gray-100 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-white/[0.02]">
                        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">კატეგორიები</h3>
                                    <div class="space-y-2">
                                        @foreach($detail['categories'] as $category)
                                            <div wire:key="doctor-detail-category-{{ $doctor['id'] }}-{{ $category['key'] }}">
                                                <div class="flex items-center justify-between gap-2 text-xs"><span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $category['label'] }}</span><span class="shrink-0 tabular-nums text-gray-500">{{ number_format($category['percentage'], 1) }}%</span></div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-emerald-400" style="width: {{ min(100, $category['percentage']) }}%"></div></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">შესრულებული სამუშაოები</h3>
                                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @forelse($detail['procedures'] as $procedure)
                                            <div wire:key="doctor-procedure-{{ $doctor['id'] }}-{{ md5($procedure['name']) }}" class="flex items-center justify-between gap-2 py-1.5 text-xs"><span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $procedure['name'] }} ×{{ $procedure['count'] }}</span><strong class="shrink-0 tabular-nums text-gray-900 dark:text-white">{{ \App\Support\Currency::format($procedure['revenue'], $currency) }}</strong></div>
                                        @empty
                                            <div class="py-4 text-center text-xs text-gray-500">სამუშაოები არ არის.</div>
                                        @endforelse
                                    </div>
                                </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">არჩეულ პერიოდში ექიმების მონაცემები არ არის.</div>
        @endforelse
    </div>
</section>
