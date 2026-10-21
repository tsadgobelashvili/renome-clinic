<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Bank\BankClassificationRepair;
use App\Services\Finance\AccountingLedger;
use Illuminate\Console\Command;

class RepairBankClassification extends Command
{
    protected $signature = 'bank:repair-classification {--dry-run} {--apply} {--owner=} {--evidence=} {--from=} {--until=}';

    protected $description = 'Read-only fallback diagnostic by default; evidence-gated classification-only repair';

    public function handle(BankClassificationRepair $repair): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply.');

            return self::FAILURE;
        }
        $dates = validator(['from' => $this->option('from'), 'until' => $this->option('until')], [
            'from' => 'nullable|date_format:Y-m-d',
            'until' => ['nullable', 'date_format:Y-m-d', ...($this->option('from') ? ['after_or_equal:from'] : [])],
        ])->validate();
        $evidence = $this->option('evidence') ? json_decode(file_get_contents($this->option('evidence')), true, flags: JSON_THROW_ON_ERROR) : [];
        if ($this->option('apply') && (! $evidence || ! ($owner = User::find($this->option('owner')))?->isOwner())) {
            $this->error('APPLY requires a valid Owner ID and reviewed forensic evidence.');

            return self::FAILURE;
        }
        $rows = $this->option('apply') ? $repair->apply($evidence, $owner, $dates['from'], $dates['until'])
            : $repair->inspect($evidence, $dates['from'], $dates['until']);
        $this->line(json_encode(['mode' => $this->option('apply') ? 'APPLY' : 'DRY RUN', 'candidates' => count($rows),
            'eligible' => count(array_filter($rows, fn ($row) => $row['eligible'])), 'records' => $rows,
            'pnl' => app(AccountingLedger::class)->pnlTotals(null, null)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
