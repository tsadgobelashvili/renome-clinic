<x-filament-panels::page>
    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
        <span><strong>{{ $advance->employee->full_name }}</strong> · {{ $advance->date->format('d.m.Y') }} · {{ \App\Models\EmployeeAdvance::SOURCES[$advance->source] }}</span>
        <span>გაცემული: <strong>{{ number_format((float) $advance->amount, 2) }} GEL</strong></span>
        <span>RS: {{ number_format((float) ($totals['rs'] ?? 0), 2) }} GEL</span>
        @if ((float) $unallocated > 0)
            <span class="text-warning-600">გაუნაწილებელი RS: {{ number_format((float) $unallocated, 2) }} GEL</span>
        @endif
        <span>სხვა ხარჯები: {{ number_format((float) ($totals['manual'] ?? 0), 2) }} GEL</span>
        <span>დაბრუნებული: {{ number_format((float) ($totals['return'] ?? 0), 2) }} GEL</span>
        <span>დარჩენილი: <strong>{{ number_format((float) $advance->remaining_amount, 2) }} GEL</strong></span>
        @if ((float) $advance->overspent_amount > 0)
            <span class="text-warning-600">თანამშრომლისთვის ასანაზღაურებელი: <strong>{{ number_format((float) $advance->overspent_amount, 2) }} GEL</strong></span>
        @endif
        <x-filament::badge :color="$advance->status === 'settled' ? 'success' : 'gray'">{{ \App\Models\EmployeeAdvance::STATUSES[$advance->status] }}</x-filament::badge>
    </div>
    @if ($advance->note)<p class="text-sm text-gray-500">{{ $advance->note }}</p>@endif
    <p class="text-sm text-gray-500">RS დოკუმენტის მისაბმელად გახსენით დოკუმენტი და აირჩიეთ „ავანსთან მიბმა“. დადასტურებული ჩანაწერები უცვლელია.</p>
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800"><tr>
                @foreach (['თარიღი', 'ტიპი', 'დანიშნულება / დოკუმენტი', 'კლასიფიკაცია', 'თანხა'] as $heading)
                    <th class="px-3 py-2 text-start">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr class="border-t border-gray-100 even:bg-gray-50 dark:border-gray-700 dark:even:bg-gray-800">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $entry->expense_date->format('d.m.Y') }}</td>
                        <td class="px-3 py-2">{{ ['rs' => 'RS', 'manual' => 'ხარჯი', 'return' => 'დაბრუნება'][$entry->kind] }}</td>
                        <td class="px-3 py-2" title="{{ $entry->note }}">
                            @if ($entry->purchase_id)
                                <a class="text-primary-600" href="{{ \App\Filament\Resources\Purchases\PurchaseResource::getUrl('edit', ['record' => $entry->purchase_id]) }}">{{ $entry->description }}</a>
                            @else {{ $entry->description }} @endif
                        </td>
                        <td class="px-3 py-2">{{ $entry->kind === 'rs' ? 'RS პროდუქციის მიხედვით' : implode(' → ', array_filter([$entry->direction?->name, $entry->expenseType?->name])) }}</td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">{{ number_format((float) $entry->amount, 2) }} GEL</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-gray-500">ჯერ არ არის დადასტურებული ხარჯი.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
</x-filament-panels::page>
