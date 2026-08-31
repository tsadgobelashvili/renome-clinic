<div class="space-y-3">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['დაუხურავი სამუშაო', 'work_total'],
            ['პირდაპირი ხარჯები', 'expense_total'],
            ['საბაზო თანხა', 'base_total'],
            ['სავარაუდო ხელფასი', 'doctor_share'],
        ] as [$label, $key])
            <div class="min-w-0 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-white/10">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 space-y-0.5 text-base font-semibold text-gray-950 dark:text-white">
                    @forelse (App\Support\Currency::formatBreakdown($summary['totals'], $key) as $amount)
                        <div class="whitespace-nowrap">{{ $amount }}</div>
                    @empty
                        <div class="whitespace-nowrap">0.00 ₾</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="border-t border-gray-100 pt-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
        @if ($summary['last_settled_at'] ?? null)
            <span class="font-medium text-gray-700 dark:text-gray-300">ბოლო ხელფასი:</span>
            {{ $summary['last_settled_at']->format('d.m.Y') }}
            <span class="mx-1">·</span>
            <span class="font-medium text-gray-700 dark:text-gray-300">პაციენტი:</span>
            {{ $summary['last_patient'] ?? '—' }}
            @if (filled($summary['last_visit_id'] ?? null))
                <span class="mx-1">·</span> Visit #{{ $summary['last_visit_id'] }}
            @endif
        @else
            ბოლო ხელფასი ჯერ არ დაფიქსირებულა
        @endif
    </div>
</div>
