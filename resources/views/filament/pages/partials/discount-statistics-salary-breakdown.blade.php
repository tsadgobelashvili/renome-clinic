<section class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900" data-discount-salary-breakdown>
    <div class="flex items-center gap-2 px-3 pt-3">
        <h2 class="text-sm font-bold">{{ __('discount-statistics.doctor_salary') }}</h2>
        <button type="button" class="text-xs text-gray-400" title="{{ __('discount-statistics.counts_note') }}" aria-label="{{ __('discount-statistics.counts_note') }}">ⓘ</button>
    </div>
    <div class="flex flex-wrap gap-x-6 gap-y-2 px-3 py-3">
        @foreach (['generated' => 'paid', 'no_salary' => 'not_paid'] as $status => $label)
            <button type="button" wire:click='openDetails(@json(["salary_status" => $status]))' data-salary-breakdown="{{ $status }}" class="flex items-center gap-2 rounded text-xs hover:text-primary-600" aria-expanded="{{ ($detailsScope['salary_status'] ?? null) === $status ? 'true' : 'false' }}">
                <span class="font-semibold">▸ {{ __('discount-statistics.'.$label) }}</span>
                <span class="text-gray-500">{{ number_format($summary->{$status.'_visits'}) }} {{ __('discount-statistics.visits') }} · {{ number_format($summary->{$status.'_items'}) }} {{ __('discount-statistics.items') }}</span>
            </button>
        @endforeach
    </div>
    <details class="border-t border-gray-100 dark:border-white/10">
        <summary class="cursor-pointer px-3 py-2 text-xs text-gray-500">{{ __('discount-statistics.statuses_title') }}</summary>
        <div class="overflow-x-auto">
            <div class="renome-discount-row text-gray-500"><span></span>@foreach (['patients', 'visits', 'quantity', 'original_value', 'salary'] as $heading)<span class="text-right">{{ __('discount-statistics.'.$heading) }}</span>@endforeach</div>
            @foreach ($report['statuses'] as $row)
                <div class="renome-discount-row">
                    <button type="button" class="text-primary-600" wire:click='openDetails(@json(["salary_status" => $row->salary_status]))'>{{ __('discount-statistics.status_labels.'.$row->salary_status) }}</button>
                    @include('filament.pages.partials.discount-statistics-metrics')
                </div>
            @endforeach
        </div>
    </details>
</section>
