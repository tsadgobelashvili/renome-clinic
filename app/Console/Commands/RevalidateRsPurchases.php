<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RsHistoricalRevalidation;
use Illuminate\Console\Command;

class RevalidateRsPurchases extends Command
{
    protected $signature = 'purchases:revalidate-rs
        {--import= : Completed retained import UUID}
        {--owner= : Existing Owner user ID for authorization and audit}
        {--dry-run : Read-only preview (the default)}
        {--apply : Apply the reviewed dry-run plan}
        {--fingerprint= : Exact fingerprint printed by DRY RUN}';

    protected $description = 'Preview or explicitly repair/revalidate unaccounted historical RS purchases';

    public function handle(RsHistoricalRevalidation $service): int
    {
        $owner = User::find($this->option('owner'));
        if (! $owner || ! $owner->canManageOwnerModules()) {
            $this->error('An existing Owner is required. No changes made.');

            return self::FAILURE;
        }
        if ($this->option('apply') && ($this->option('dry-run') || ! $this->option('fingerprint'))) {
            $this->error('APPLY requires a prior DRY RUN fingerprint and cannot be combined with --dry-run.');

            return self::FAILURE;
        }
        try {
            $plan = $this->option('apply')
                ? $service->apply((string) $this->option('import'), $owner, (string) $this->option('fingerprint'))
                : $service->preview((string) $this->option('import'), $owner);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'APPLY completed atomically.' : 'DRY RUN: no records changed.');
        $this->line('Fingerprint: '.$plan['fingerprint']);
        $this->table(['Check', 'Count'], [
            ['Documents to revalidate', count($plan['changes'])],
            ['Obsolete precision lines to remove', collect($plan['changes'])->sum(fn ($c) => count($c['remove']))],
            ['Rounded lines to upgrade', collect($plan['changes'])->sum(fn ($c) => count($c['upgrade']))],
            ['Unchanged documents', count($plan['unchanged'])],
            ['Documents left for review', count($plan['review'])],
            ['File-level errors retained', count($plan['file_errors'])],
            ['Attributable rejected rows retained', collect($plan['attributable_errors'])->sum(fn ($errors) => count($errors))],
            ['Negative documents excluded, preserved', count($plan['legacy_negative_documents'])],
        ]);
        $repairs = collect($plan['changes'])->filter(fn ($c) => $c['remove'] !== [] || $c['upgrade'] !== []);
        if ($repairs->isNotEmpty()) {
            $this->table(['Purchase', 'Document', 'Old total', 'Source total', 'Remove item IDs', 'Upgrade item IDs'],
                $repairs->map(fn ($c) => [$c['purchase_id'], $c['document_number'], $c['old_total'], $c['source_total'], implode(',', $c['remove']), implode(',', array_keys($c['upgrade']))])->all());
        }
        foreach ($plan['review'] as $id => $reason) {
            $this->warn('Review purchase #'.$id.': '.$reason);
        }
        $this->line('Finance, Cashier, Bank, Advances and Product classifications are never written by this command.');

        return self::SUCCESS;
    }
}
