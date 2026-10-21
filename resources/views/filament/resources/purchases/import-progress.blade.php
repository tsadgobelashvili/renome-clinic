@if ($this->rsImportRun)
    <x-filament::section>
        <div @if (! $this->rsImportPaused && ($this->rsImportProgress['phase'] ?? '') !== 'completed') wire:poll.1s="continueRsImport" @endif
             class="space-y-2 text-sm" role="status" aria-live="polite">
            <p class="font-semibold">RS იმპორტი — {{ ($this->rsImportProgress['phase'] ?? '') === 'completed' ? 'დასრულდა' : 'მიმდინარეობს' }}</p>
            @if (($this->rsImportProgress['phase'] ?? '') === 'preparing')
                <p>ფაილი შენახულია. მიმდინარეობს სტრიქონების მომზადება…</p>
            @endif
            <p>დამუშავებულია: {{ $this->rsImportProgress['processed'] ?? 0 }} / {{ $this->rsImportProgress['total'] ?? 0 }}</p>
            <p>დოკუმენტები: {{ $this->rsImportProgress['documents_imported'] ?? 0 }} · პროდუქტები: {{ $this->rsImportProgress['imported'] ?? 0 }} · დუბლიკატები: {{ $this->rsImportProgress['skipped'] ?? 0 }} · შეცდომები: {{ $this->rsImportProgress['failed_rows'] ?? 0 }}</p>
            @if (($this->rsImportProgress['review_rows'] ?? 0) > 0)
                <p>სტატუსი / ტიპი გადასამოწმებელია: {{ $this->rsImportProgress['review_rows'] }}</p>
            @endif
            @if (($this->rsImportProgress['phase'] ?? '') === 'finalizing')
                <p>მიმდინარეობს სრული დოკუმენტების საბოლოო გადამოწმება…</p>
            @endif
            <span wire:loading wire:target="continueRsImport,resumeRsImport">მუშავდება შემდეგი ნაწილი…</span>
            @if ($this->rsImportPaused)
                <p>იმპორტი შეჩერებულია. შენახული სტრიქონები დაცულია.</p>
                <x-filament::button wire:click="resumeRsImport" wire:loading.attr="disabled">გაგრძელება</x-filament::button>
            @elseif (($this->rsImportProgress['phase'] ?? '') !== 'completed')
                <p>დატოვეთ გვერდი ღია. დაბრუნებისას დამუშავება გაგრძელდება შენახული პოზიციიდან.</p>
            @endif
            @if (! empty($this->rsImportProgress['errors']) || ($this->rsImportProgress['review_rows'] ?? 0) > 0)
                <a class="underline" href="{{ route('purchases.import-report', $this->rsImportProgress['error_report_id']) }}">ანგარიშის ჩამოტვირთვა</a>
            @endif
        </div>
    </x-filament::section>
@endif
