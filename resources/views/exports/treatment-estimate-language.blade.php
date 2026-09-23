<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RenoMe — Export</title>
    <style>
        body { font-family: system-ui, sans-serif; color: #1f2937; background: #f8fafc; margin: 0; padding: 24px; }
        main { max-width: 480px; margin: 8vh auto; padding: 24px; border: 1px solid #d1d5db; border-radius: 12px; background: white; }
        h1 { font-size: 20px; margin: 0 0 20px; }
        label { display: block; margin: 0 0 8px; }
        select { box-sizing: border-box; width: 100%; padding: 10px; border: 1px solid #9ca3af; border-radius: 6px; font: inherit; }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        button { padding: 8px 20px; border: 1px solid #6b7280; border-radius: 6px; background: white; font: inherit; cursor: pointer; }
        button:hover { background: #f3f4f6; }
    </style>
</head>
<body>
    <main>
        <h1>RenoMe — {{ $estimate->patient?->full_name }}</h1>
        <form method="GET" action="{{ route('treatment-estimates.'.array_key_first($formats), ['patient' => $estimate->patient_id, 'estimate' => $estimate]) }}">
            <label for="export-language">ენა / Language / Язык</label>
            <select id="export-language" name="language" required>
                @foreach ($languages as $value => $label)
                    <option value="{{ $value }}" @selected($language === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="actions">
                @foreach ($formats as $format => $label)
                    <button type="submit" formaction="{{ route('treatment-estimates.'.$format, ['patient' => $estimate->patient_id, 'estimate' => $estimate]) }}">{{ $label }}</button>
                @endforeach
            </div>
        </form>
    </main>
</body>
</html>
