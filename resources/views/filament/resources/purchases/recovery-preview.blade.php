@if ($preview)
    <div class="space-y-4" data-rs-recovery-preview>
        <p><strong>RS დოკუმენტი:</strong> {{ $preview['source']['document_number'] }} · {{ $preview['source']['supplier'] }} · {{ $preview['source']['date'] }}</p>
        <p><strong>მიმდინარე თანხა / Current total:</strong> {{ number_format((float) $preview['current_total'], 2) }} GEL</p>
        <p><strong>ხელახალი იმპორტის თანხა / Re-imported total:</strong> {{ number_format((float) $preview['source']['total'], 2) }} GEL · {{ count($preview['source']['rows']) }} სტრიქონი</p>
        @if ($preview['source']['document_total'] === null)
            <p>თანხა არის წყაროში მოცემული სტრიქონების ჯამი. ფაილში ცალკე RS დოკუმენტის ჯამი არ არის.</p>
        @else
            <p>RS დოკუმენტის თანხა: {{ $preview['source']['document_total'] }} GEL · შეჯერებულია 0.01 GEL სიზუსტით.</p>
        @endif
        @if ($preview['consolidate_ids'])
            <p role="alert">უკვე არსებობს ამავე წყაროს გადაუხდელი ჩანაწერი #{{ implode(', #', $preview['consolidate_ids']) }}. დადასტურებისას მისი ზუსტად დამთხვევადი სტრიქონები გადმოვა #{{ $preview['purchase_id'] }}-ში; ცარიელი დუბლიკატი გაერთიანდება და მისი თავდაპირველი მონაცემები აღდგენის ისტორიაში შენარჩუნდება.</p>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th>კოდი</th><th>დასახელება</th><th>რაოდენობა</th><th>ერთეული</th><th>ერთეულის ფასი</th><th>წყაროს თანხა</th><th>კლასიფიკაცია</th></tr></thead>
                <tbody>
                @foreach ($preview['source']['rows'] as $hash => $row)
                    <tr><td>{{ $row['rs_code'] ?: $row['supplier_code'] }}</td><td>{{ $row['mapped']['item_name'] }}</td><td>{{ $row['mapped']['quantity'] }}</td><td>{{ $row['mapped']['unit'] }}</td><td>{{ $row['mapped']['unit_price'] }}</td><td>{{ $row['mapped']['line_total'] }} GEL</td><td>{{ !empty($preview['plan'][$hash]['classification']['expense_direction_id']) ? 'ზუსტი დამთხვევა — შენარჩუნდება' : 'დასაზუსტებელია' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($preview['source']['other_document_errors'])
            <p>ფაილის სხვა დოკუმენტების {{ count($preview['source']['other_document_errors']) }} არასწორი სტრიქონი ამ აღდგენაში არ მონაწილეობს. აღდგება მხოლოდ ზემოთ ნაჩვენები დოკუმენტი.</p>
        @endif
        <p>ფინანსური გატარება არ შეიქმნება. უცნობი კლასიფიკაციები სახელით არ გადაიტანება; დოკუმენტი დარჩება გადასამოწმებელი.</p>
    </div>
@endif
