<?php

namespace App\Console\Commands;

use App\Services\BogBusinessApiService;
use App\Services\BogStatementSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class BogSync extends Command
{
    protected $signature = 'bog:sync {startDate? : YYYY-MM-DD} {endDate? : YYYY-MM-DD}';

    protected $description = 'Fetch BOG statements into local unreviewed transactions; never create Finance records';

    public function handle(BogBusinessApiService $api, BogStatementSyncService $sync): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is available only in APP_ENV=local.');

            return self::FAILURE;
        }
        $dates = [
            'start' => $this->argument('startDate') ?? today()->subDay()->toDateString(),
            'end' => $this->argument('endDate') ?? today()->toDateString(),
        ];
        if (Validator::make($dates, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start'])->fails()) {
            $this->error('Use valid YYYY-MM-DD dates with startDate on or before endDate.');

            return self::FAILURE;
        }
        try {
            $response = $api->statement($dates['start'], $dates['end'], redactOutput: false);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Fetched: '.count($response['records']));
        try {
            $result = $sync->import($response['records'], config('services.bog.account_number'), config('services.bog.account_currency'));
        } catch (QueryException) {
            $this->line('Inserted: 0');
            $this->error('Database insert failed; no rows were committed. Check the bog_transactions migration and database connection.');

            return self::FAILURE;
        }
        $this->line('Inserted: '.$result['inserted']);
        $this->line('Skipped duplicates: '.$result['duplicates']);
        $this->line('Errors: '.count($result['errors']));
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
