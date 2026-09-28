<div class="overflow-x-auto rounded-lg border border-gray-200 p-3">
    <h3 class="font-semibold">{{ __('israeli-compensation.visits') }}</h3>
    <p>{{ __('israeli-compensation.method') }}: {{ __('employees.payroll.'.($method === 'bank_transfer' ? 'bank' : 'cash')) }}</p>
    <table class="w-full text-sm">
        <thead><tr><th>თარიღი</th><th>პაციენტი</th><th>სამუშაო</th><th>{{ __('israeli-compensation.basis') }}</th><th>ხელფასი</th></tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr wire:key="israeli-visit-salary-{{ $row['visit_id'] }}">
                <td class="p-2">{{ $row['visit_date'] }}</td><td class="p-2">{{ $row['patient'] }}</td>
                <td class="p-2">{{ $row['manipulations'] }}</td>
                <td class="p-2">{{ \App\Support\Currency::format($row['total_value'], $row['currency']) }}</td>
                <td class="p-2 font-semibold">{{ \App\Support\Currency::format($row['doctor_share'], $row['currency']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-3">არჩეულ პერიოდში დაუხურავი ვიზიტი არ მოიძებნა.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
