<div class="divide-y divide-gray-100 text-sm">
    @foreach ($rows as $row)
        <div class="flex justify-between gap-4 py-2">
            <span>{{ $row->id ? app(\App\Services\ExpenseDimensions::class)->labelById($row->id) : 'უკატეგორიო' }}</span>
            <span class="font-medium tabular-nums">{{ number_format($row->amount, 2) }} ₾</span>
        </div>
    @endforeach
</div>
