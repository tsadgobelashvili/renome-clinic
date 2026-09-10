@if ((float) $row->salary_gel !== 0.0 || (float) $row->salary_usd === 0.0)
    <span class="block whitespace-nowrap">{{ \App\Support\Currency::format($row->salary_gel, 'GEL') }}</span>
@endif
@if ((float) $row->salary_usd !== 0.0)
    <span class="block whitespace-nowrap">{{ \App\Support\Currency::format($row->salary_usd, 'USD') }}</span>
@endif
