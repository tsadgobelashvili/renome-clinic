<?php

namespace App\Console\Commands;

use App\Services\BogBusinessApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class BogTestStatement extends Command
{
    protected $signature = 'bog:test-statement {startDate? : YYYY-MM-DD} {endDate? : YYYY-MM-DD}';

    protected $description = 'Local-only, read-only BOG statement test (no imports or database writes)';

    public function handle(BogBusinessApiService $api): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is available only in APP_ENV=local.');

            return self::FAILURE;
        }
        $dates = [
            'start' => $this->argument('startDate') ?? today()->subDay()->toDateString(),
            'end' => $this->argument('endDate') ?? today()->toDateString(),
        ];
        $validator = Validator::make($dates, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start']);
        if ($validator->fails()) {
            $this->error('Use valid YYYY-MM-DD dates with startDate on or before endDate.');

            return self::FAILURE;
        }

        try {
            $result = $api->statement($dates['start'], $dates['end']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('HTTP status: '.$result['status']);
        $this->line('Record count: '.count($result['records']));
        foreach ($result['records'] as $record) {
            $this->output->writeln(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
