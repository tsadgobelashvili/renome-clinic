<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-4">
        <select wire:model="technicianId" class="fi-input block w-full rounded-lg border-gray-300"><option value="">{{ __('lab.technician') }}</option>@foreach($this->technicians() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        <input type="date" wire:model="periodStart" class="fi-input block w-full rounded-lg border-gray-300">
        <input type="date" wire:model="periodEnd" class="fi-input block w-full rounded-lg border-gray-300">
        <x-filament::button wire:click="calculate">Calculate</x-filament::button>
    </div>
    <div class="rounded-xl border bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 text-lg font-semibold">{{ __('lab.salary') }}: {{ number_format($report['total'], 2) }} ₾</div>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Date</th><th>Patient</th><th>Work</th><th>Qty</th><th>Rate</th><th>Salary</th></tr></thead><tbody>@foreach($report['items'] as $item)<tr class="border-t"><td>{{ $item['date'] }}</td><td>{{ $item['patient'] }}</td><td>{{ $item['work'] }} / {{ $item['component'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ number_format($item['rate'], 2) }}</td><td>{{ number_format($item['salary'], 2) }}</td></tr>@endforeach</tbody></table></div>
        @if(count($report['items']))<x-filament::button class="mt-4" wire:click="confirm">Confirm settlement</x-filament::button>@endif
    </div>
    <div class="space-y-2">@foreach($this->settlements() as $settlement)<div class="flex items-center justify-between rounded-lg border bg-white p-3 dark:bg-gray-900"><span>{{ $settlement->technician->name }} · {{ $settlement->period_start->format('d.m.Y') }}—{{ $settlement->period_end->format('d.m.Y') }} · {{ number_format($settlement->salary_total, 2) }} ₾</span><x-filament::button color="danger" size="sm" wire:click="undo({{ $settlement->id }})">Undo</x-filament::button></div>@endforeach</div>
</x-filament-panels::page>
