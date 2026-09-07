<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\LabCase;

/** Compact names for Lab list cells only. */
class LabTechnicianDisplay
{
    private ?array $firstNameCounts = null;

    public function name(?Employee $employee, ?string $fallback = null): string
    {
        $parts = preg_split('/\s+/u', trim($fallback ?? ''), 2);
        $first = trim($employee?->first_name ?? ($parts[0] ?? ''));
        $last = trim($employee?->last_name ?? ($parts[1] ?? ''));
        if ($first === '') {
            return '—';
        }
        $this->firstNameCounts ??= Employee::query()->activeTechnicians()->pluck('first_name')
            ->countBy(fn (string $name): string => mb_strtolower(trim($name), 'UTF-8'))->all();
        $duplicate = ($this->firstNameCounts[mb_strtolower($first, 'UTF-8')] ?? 0) > 1;

        return $this->initialUpper($first).($duplicate && $last !== '' ? ' '.$this->initialUpper(mb_substr($last, 0, 1, 'UTF-8')).'.' : '');
    }

    public function modeler(LabCase $case): string
    {
        if ($case->modeled_by !== null) {
            return $this->name($case->modeler?->employee, $case->modeler?->name);
        }

        return $case->mainWorks->map(fn ($work) => $work->technicianEmployee)
            ->filter(fn (?Employee $employee): bool => (bool) $employee?->salary_modeler)
            ->unique('id')->map(fn (Employee $employee): string => $this->name($employee))->join(', ') ?: '—';
    }

    private function initialUpper(string $name): string
    {
        return (string) preg_replace_callback('/^((?!\p{Georgian})\p{Ll})/u',
            fn (array $match): string => mb_strtoupper($match[1], 'UTF-8'), $name);
    }
}
