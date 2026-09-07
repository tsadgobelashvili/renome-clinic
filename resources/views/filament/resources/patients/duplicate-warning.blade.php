<div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
    <div class="font-semibold">A similar patient may already exist.</div>
    <div class="mt-1 text-amber-800">Review the possible matches below. You may use an existing patient or continue creating this record anyway.</div>

    <div class="mt-3 divide-y divide-amber-200 rounded-md border border-amber-200 bg-white">
        @foreach ($matches as $match)
            <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                <div>
                    <div class="font-medium text-gray-950">{{ $match->full_name }}</div>
                    <div class="text-xs text-gray-600">
                        {{ implode(' · ', array_filter([
                            $match->formatted_patient_number,
                            $match->birth_date?->format('d.m.Y'),
                            $match->phone,
                        ])) }}
                    </div>
                </div>
                <a
                    class="font-semibold text-primary-700 hover:text-primary-800 hover:underline"
                    href="{{ \App\Filament\Resources\Patients\PatientResource::getUrl('view', ['record' => $match]) }}"
                >
                    Use existing patient
                </a>
            </div>
        @endforeach
    </div>

    <div class="mt-3 flex justify-end">
        <button type="submit" class="rounded-md border border-amber-400 bg-white px-3 py-1.5 font-semibold text-amber-900 hover:bg-amber-100">
            Create anyway
        </button>
    </div>
</div>
