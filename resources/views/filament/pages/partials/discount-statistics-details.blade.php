<section class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900" data-discount-patient-details tabindex="-1" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' }); $el.focus({ preventScroll: true })">
    <div class="flex items-center justify-between gap-3 px-3 py-3">
        <div><h2 class="text-sm font-bold">{{ __('discount-statistics.details') }}@if (in_array($detailsScope['salary_status'] ?? null, ['generated', 'no_salary'])) · {{ __('discount-statistics.doctor_salary') }}: {{ __('discount-statistics.'.($detailsScope['salary_status'] === 'generated' ? 'paid' : 'not_paid')) }}@endif</h2><p class="text-[11px] text-gray-500">{{ __('discount-statistics.detail_note') }}</p></div>
        <x-filament::button size="sm" color="gray" wire:click="closeDetails">{{ __('discount-statistics.close') }}</x-filament::button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50 text-[10px] text-gray-500 dark:bg-white/5"><tr>
                @foreach (['date', 'patient', 'doctor', 'source', 'manipulation', 'quantity', 'original_value', 'salary', 'salary_paid', 'reason'] as $heading)
                    <th @class(['px-3 py-2 font-medium', 'text-right' => in_array($heading, ['quantity', 'original_value', 'salary']), 'text-left' => !in_array($heading, ['quantity', 'original_value', 'salary'])])>{{ __('discount-statistics.'.$heading) }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($detailRows as $row)
                    <tr wire:key="discount-detail-{{ $row->visit_id }}-{{ $row->item_id }}" class="align-top hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::parse($row->visit_date)->format('d.m.Y') }}<a class="block text-[10px] text-primary-600" href="{{ \App\Filament\Resources\Visits\VisitResource::getUrl('edit', ['record' => $row->visit_id]) }}">Visit #{{ $row->visit_id }}</a></td>
                        <td class="px-3 py-2 font-medium">{{ $row->patient_first_name }} {{ $row->patient_last_name }}</td>
                        <td class="px-3 py-2">{{ trim($row->doctor_first_name.' '.$row->doctor_last_name) ?: __('discount-statistics.no_doctor') }}</td>
                        <td class="px-3 py-2">{{ $row->source === 'israel-partner' ? __('discount-statistics.israeli') : ($row->source === 'clinic' ? __('discount-statistics.clinic') : $row->source) }}</td>
                        <td class="px-3 py-2">{{ $row->service_name ?: __('discount-statistics.no_items') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row->quantity }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ \App\Support\Currency::format($row->original_value, $row->currency) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-primary-700 dark:text-primary-300">@include('filament.pages.partials.discount-statistics-salary')</td>
                        <td class="px-3 py-2 text-[11px]"><span class="font-semibold">{{ __('discount-statistics.'.($row->salary_status === 'generated' ? 'yes' : 'no')) }}</span><span class="block text-gray-500">{{ __('discount-statistics.status_labels.'.$row->salary_status) }}</span></td>
                        <td class="px-3 py-2">{{ \App\Services\FullDiscountStatistics::reasonLabel($row->reason) }}@if (filled($row->discount_comment))<span class="mt-1 block text-[11px] text-gray-500">{{ $row->discount_comment }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-5 text-center text-gray-500">{{ __('discount-statistics.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex items-center justify-end gap-3 px-3 py-3 text-xs">
        <button type="button" wire:click="changeDetailsPage({{ max(1, $detailsPage - 1) }})" @disabled($detailsPage <= 1) class="text-primary-600 disabled:opacity-40">{{ __('discount-statistics.previous') }}</button>
        <span>{{ __('discount-statistics.page') }} {{ $detailsPage }}</span>
        <button type="button" wire:click="changeDetailsPage({{ $detailsPage + 1 }})" @disabled(!$detailRows->hasMorePages()) class="text-primary-600 disabled:opacity-40">{{ __('discount-statistics.next') }}</button>
    </div>
</section>
