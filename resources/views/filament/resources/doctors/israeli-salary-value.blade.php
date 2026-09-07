<div class="renome-salary-summary-card rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
    <div class="renome-salary-summary-label text-[11px] text-gray-500">{{ $label }}</div>
    <div @class([
        'renome-salary-summary-value text-right text-sm font-semibold tabular-nums',
        'text-gray-500' => in_array($value, ['—', '$0.00', '0.00 ₾'], true),
        'text-success-600 dark:text-success-400' => in_array($label, ['გასაცემი', 'გაცემული'], true) && ! in_array($value, ['—', '$0.00', '0.00 ₾'], true),
        'text-warning-600 dark:text-warning-400' => $label === 'სხვაობა' && str_contains($value, 'ავანსი'),
        'text-danger-600 dark:text-danger-400' => $label === 'სხვაობა' && str_contains($value, 'დარჩა'),
        'text-gray-950 dark:text-white' => ! in_array($label, ['გასაცემი', 'გაცემული', 'სხვაობა'], true) && ! in_array($value, ['—', '$0.00', '0.00 ₾'], true),
    ])>{{ $value }}</div>
</div>
