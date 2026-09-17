<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\BankCategories;
use App\Models\BankCategory;
use App\Models\BankImportBatch;
use App\Models\BankTransaction;
use App\Models\Doctor;
use App\Models\ExpenseCategory;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Bank\BankImportRollbackService;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BankReport;
use App\Services\Bank\BankStatementImportService;
use App\Services\Bank\BogStatementParser;
use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Storage::fake('local');
});

function bogRows(): array
{
    return [
        ['საქართველოს ბანკი / Bank of Georgia', 'Business Online'],
        ['Account:', 'GE00BG0000000000000000'],
        ['Currency', 'GEL'],
        ['Statement period', '01.09.2026 - 11.09.2026'],
        ['Opening balance', '1,000.00'],
        ['Closing balance', '1,450.00'],
        [],
        ['თარიღი', 'ოპერაციის ID', 'Ref', 'ოპერაციის ტიპი', 'დებეტი', 'კრედიტი', 'ვალუტა', 'კონტრაგენტი', 'კონტრაგენტის ანგარიში', 'დანიშნულება', 'საკომისიო', 'ნაშთი', 'Gross amount', 'unused technical field'],
        [new DateTimeImmutable('2026-09-10'), '000123', 'ref-1', 'PBS', '', 500, 'GEL', 'ქართული კომპანია', 'GE00COUNTERPARTY', 'ბარათის ჩარიცხვა', 5, 1500, 505, 'retained'],
        ['11.09.2026', null, 'ref-2', 'TRN', '50,00', '', 'GEL', 'მომწოდებელი', null, 'მასალები', '', 1450],
        ['Total', null, null, null, 50, 500],
    ];
}

function bogWorkbook(?array $rows = null, string $sheetName = 'Sheet1'): string
{
    Storage::disk('local')->makeDirectory('bank-fixtures');
    $path = Storage::disk('local')->path('bank-fixtures/'.uniqid().'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName($sheetName);
    foreach ($rows ?? bogRows() as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

function importBog(?array $rows = null): BankImportBatch
{
    return app(BankStatementImportService::class)->import(bogWorkbook($rows), 'original BOG.xlsx', auth()->id());
}

test('original xlsx detects Georgian headers below summary and imports normalized movements with raw data', function () {
    $batch = importBog();
    expect($batch->errors)->toBe([]);
    expect($batch->imported_rows)->toBe(2)->and($batch->duplicate_rows)->toBe(0)->and($batch->rejected_rows)->toBe(0);
    expect($batch->opening_balance)->toBe('1000.00')->and($batch->closing_balance)->toBe('1450.00')
        ->and($batch->reported_balance)->toBe('1450.00')->and($batch->balance_origin)->toBe('closing_balance')
        ->and($batch->accounts)->toBe(['GE00BG0000000000000000'])->and($batch->currencies)->toBe(['GEL'])
        ->and($batch->period_from->toDateString())->toBe('2026-09-01')->and($batch->period_to->toDateString())->toBe('2026-09-11');
    $inflow = BankTransaction::where('operation_id', '000123')->firstOrFail();
    expect($inflow->amount)->toBe('500.00')->and($inflow->direction)->toBe('inflow')->and($inflow->bank_fee)->toBe('5.00')
        ->and($inflow->gross_amount)->toBe('505.00')->and($inflow->operation_type)->toBe('PBS')
        ->and($inflow->counterparty_name)->toBe('ქართული კომპანია')->and($inflow->raw_data['cells'][13]['value'])->toBe('retained');
    $outflow = BankTransaction::where('reference', 'ref-2')->firstOrFail();
    expect($outflow->direction)->toBe('outflow')->and($outflow->amount)->toBe('50.00')->and($outflow->bank_fee)->toBe('0.00');
});

test('same unchanged file and renamed copy deduplicate both operation ID and fallback fingerprint', function () {
    $file = bogWorkbook();
    $service = app(BankStatementImportService::class);
    $service->import($file, 'first.xlsx');
    $second = $service->import($file, 'renamed.xlsx');
    expect($second->imported_rows)->toBe(0)->and($second->duplicate_rows)->toBe(2)
        ->and(BankTransaction::count())->toBe(2)->and(BankImportBatch::count())->toBe(2);
    expect(BankTransaction::first()->source_file)->toBe('first.xlsx');
});

test('operation identifiers are scoped by bank account and currency', function () {
    importBog();
    $rows = bogRows();
    $rows[1][1] = 'GE00BG0000000000000001';
    expect(importBog($rows)->imported_rows)->toBe(2);
    $rows[2][1] = 'USD';
    $rows[8][6] = $rows[9][6] = 'USD';
    expect(importBog($rows)->imported_rows)->toBe(2);
    expect(BankTransaction::count())->toBe(6);
});

test('conflicting operation ID rolls back the entire import including earlier valid new rows', function () {
    importBog();
    $rows = bogRows();
    $new = $rows[8];
    $new[1] = 'new-transaction';
    $rows[8][5] = 501;
    array_splice($rows, 8, 0, [$new]);
    expect(fn () => importBog($rows))->toThrow(DomainException::class);
    expect(BankTransaction::count())->toBe(2)->and(BankImportBatch::count())->toBe(1);
});

test('invalid rows are reported without guessing dates amounts or directions', function () {
    $rows = bogRows();
    $badDate = $rows[8];
    $badDate[0] = '31.02.2026';
    $badAmount = $rows[8];
    $badAmount[5] = 'not money';
    $both = $rows[8];
    $both[4] = 10;
    $rows = [...$rows, $badDate, $badAmount, $both];
    $batch = importBog($rows);
    expect($batch->imported_rows)->toBe(2)->and($batch->rejected_rows)->toBe(3)->and($batch->errors)->toHaveCount(3);
});

test('unrecognized invalid and legacy files create no records', function () {
    expect(fn () => importBog([['Random report'], ['Date', 'Amount'], ['11.09.2026', 42]]))->toThrow(DomainException::class);
    $path = Storage::disk('local')->path('invalid.xlsx');
    file_put_contents($path, 'not an Excel workbook');
    expect(fn () => app(BankStatementImportService::class)->import($path, 'invalid.xlsx'))->toThrow(DomainException::class);
    expect(fn () => app(BogStatementParser::class)->parse('statement.xls'))->toThrow(DomainException::class);
    expect(BankTransaction::count())->toBe(0)->and(BankImportBatch::count())->toBe(0);
});

test('bilingual header and blank optional columns preserve movement facts', function () {
    $rows = bogRows();
    $rows[7][0] = 'თარიღი / Transaction date';
    $rows[7][4] = "დებეტი\nDebit";
    $rows[8][3] = 'COM';
    $rows[8][10] = null;
    $rows[8][12] = null;
    importBog($rows);
    $record = BankTransaction::whereNotNull('operation_id')->firstOrFail();
    expect($record->operation_type)->toBe('COM')->and($record->bank_fee)->toBe('0.00')->and($record->gross_amount)->toBeNull()->and($record->category->code)->toBe('uncategorized');
});

test('running balances establish latest snapshot even in reverse chronological statements', function () {
    $rows = bogRows();
    unset($rows[3], $rows[4], $rows[5]);
    [$rows[8], $rows[9]] = [$rows[9], $rows[8]];
    $batch = importBog(array_values($rows));
    expect($batch->reported_balance)->toBe('1450.00')->and($batch->balance_origin)->toBe('balance_after')
        ->and($batch->balance_as_of->toDateString())->toBe('2026-09-11');
});

test('inconsistent running balance and undated closing balance do not manufacture a balance', function () {
    $rows = bogRows();
    unset($rows[3]);
    $rows[9][11] = 1400;
    expect(importBog(array_values($rows))->reported_balance)->toBeNull();
    expect(app(BankReport::class)->balances())->toBeEmpty();
});

test('older statement imported later does not replace a newer dated account balance', function () {
    importBog();
    $rows = bogRows();
    $rows[3][1] = '01.08.2026 - 31.08.2026';
    $rows[5][1] = 1000;
    $rows[8][0] = '30.08.2026';
    $rows[8][1] = 'older';
    $rows[9][0] = '31.08.2026';
    importBog($rows);
    expect((float) app(BankReport::class)->balances()->sole()->reported_balance)->toBe(1450.0);
});

test('overlapping month to date statement skips known operations and updates the reported closing balance', function () {
    importBog();
    $rows = bogRows();
    $rows[3][1] = '01.09.2026 - 14.09.2026';
    $rows[5][1] = 1650;
    array_splice($rows, 10, 0, [['12.09.2026', 'new-operation', 'ref-new', 'PBS', '', 200, 'GEL', 'Deposit', '', 'Cash deposit', '', 1650]]);
    $this->travelTo('2026-09-15 12:00:00');
    $batch = importBog($rows);
    expect($batch->imported_rows)->toBe(1)->and($batch->duplicate_rows)->toBe(2)->and($batch->rejected_rows)->toBe(0)
        ->and(BankTransaction::count())->toBe(3)->and((float) app(BankReport::class)->balances()->sole()->reported_balance)->toBe(1650.0);
    Livewire::test(Bank::class)->assertDontSee('Last updated 1 day ago')->assertSet('bogBalance', null)
        ->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-02')->assertSet('bogBalance', null)
        ->set('showHistory', true)->assertViewHas('history', fn ($rows) => $rows->contains(fn ($row) => (float) $row->closing_balance === 1650.0));
});

test('SQL totals apply the same date direction currency category operation and search filters', function () {
    importBog();
    $category = BankCategory::first();
    BankTransaction::where('direction', 'inflow')->update(['bank_category_id' => $category->id]);
    $report = app(BankReport::class);
    $filters = ['dateFrom' => '2026-09-10', 'dateUntil' => '2026-09-10', 'direction' => 'inflow', 'currency' => 'GEL', 'category' => $category->id, 'operationType' => 'PBS', 'search' => '000123'];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $totals = $report->totals($filters)->sole();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect((float) $totals->inflow)->toBe(500.0)->and((float) $totals->outflow)->toBe(0.0)->and((float) $totals->fees)->toBe(5.0)
        ->and($queries)->toHaveCount(1)->and(strtolower($queries[0]['query']))->toContain('sum(', 'group by');
    foreach (['ქართული', 'GE00COUNTERPARTY', 'ბარათის', 'ref-1'] as $search) {
        expect($report->query([...$filters, 'search' => $search])->count())->toBe(1);
    }
    expect($report->totals([...$filters, 'currency' => 'EUR']))->toBeEmpty();
});

test('bank page presets custom dates and lazy details work without loading raw data on first render', function () {
    importBog();
    $record = BankTransaction::where('direction', 'inflow')->firstOrFail();
    Livewire::test(Bank::class)->assertOk()->assertSet('period', '7d')->assertSet('dateFrom', '2026-09-05')
        ->assertViewHas('transactionDetail', null)->assertViewHas('history', null)
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 2 && ! array_key_exists('raw_data', $rows->first()->getAttributes()))
        ->call('applyPeriod', '1m')->assertSet('dateFrom', '2026-08-12')
        ->call('applyPeriod', '3m')->assertSet('dateFrom', '2026-06-12')
        ->call('applyPeriod', '1y')->assertSet('dateFrom', '2025-09-12')
        ->set('dateFrom', '2026-09-11')->assertSet('period', 'custom')->assertViewHas('transactions', fn ($rows) => $rows->total() === 1)
        ->call('showTransaction', $record->id)->assertDontSee('retained')
        ->call('showTransaction', null)->set('showHistory', true)->assertSee('original BOG.xlsx')
        ->call('showBatch', BankImportBatch::first()->id)->assertViewHas('batchDetail', fn ($batch) => $batch->imported_rows === 2);
});

test('owner manages categories and inactive historical assignments remain readable', function () {
    importBog();
    Livewire::test(BankCategories::class)->call('edit')->set('name', 'Editable category')->call('save')->assertHasNoErrors();
    $category = ExpenseCategory::where('name', 'Editable category')->firstOrFail();
    $record = BankTransaction::where('direction', 'outflow')->first();
    Livewire::test(Bank::class)->call('showTransaction', $record->id)->set('expenseCategoryId', $category->id)->call('saveExpenseClassification')->assertHasNoErrors();
    Livewire::test(BankCategories::class)->call('edit', $category->id)->set('name', 'Renamed')->set('active', false)->call('save')->assertHasNoErrors();
    $other = BankTransaction::where('direction', 'inflow')->first();
    Livewire::test(Bank::class)->assertSee('Renamed')->call('showTransaction', $other->id)->set('expenseCategoryId', $category->id)->call('saveExpenseClassification')->assertHasErrors('expense_category_id');
    expect($record->fresh()->expenseCategory->name)->toBe('Renamed');
});

test('non-owner cannot access bank data or category management', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(Bank::class)->assertForbidden();
    Livewire::test(BankCategories::class)->assertForbidden();
});

test('bank ingestion never changes existing cash payments or clinic and Israeli ledgers', function () {
    $patient = Patient::create(['first_name' => 'Bank', 'last_name' => 'Isolation']);
    $doctor = Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'total_price' => 1000, 'currency' => 'GEL']);
    Payment::createWithSplits(['visit_id' => $visit->id, 'amount' => 500, 'currency' => 'GEL', 'payment_date' => today()], [['payment_method' => 'cash', 'amount' => 200], ['payment_method' => 'card', 'amount' => 300]]);
    $day = app(CashboxManager::class)->today();
    $tables = ['payments', 'payment_splits', 'cashbox_days', 'cashbox_transactions', 'finance_transactions', 'partner_finance_transactions', 'partner_patient_payments'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $cashBefore = $day->summary();
    importBog();
    $after = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    expect($after->all())->toBe($before->all())->and($day->fresh()->summary())->toBe($cashBefore);
    app(BankImportRollbackService::class)->rollback(BankImportBatch::sole()->id, auth()->user());
    $afterRollback = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    expect($afterRollback->all())->toBe($before->all())->and($day->fresh()->summary())->toBe($cashBefore);
});

test('future API adapter shares DTO ingestion and deduplication without new accounting side effects', function () {
    importBog();
    $data = iterator_to_array(app(BogStatementParser::class)->parse(bogWorkbook())['transactions'])[0];
    expect(app(BankIngestionService::class)->ingest([$data], 'api'))->toBe(['imported_rows' => 0, 'duplicate_rows' => 1]);
    $new = new BankTransactionData([...$data->attributes, 'operation_id' => 'api-new']);
    expect(app(BankIngestionService::class)->ingest([$new], 'api')['imported_rows'])->toBe(1);
    expect(BankTransaction::where('operation_id', 'api-new')->sole()->source)->toBe('api');
});

test('page query count stays constant as transaction rows grow', function () {
    importBog();
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test(Bank::class)->assertOk();
    $countQueries = fn () => count(array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'select') && str_contains($query['query'], 'bank_')));
    $baseline = $countQueries();
    $data = iterator_to_array(app(BogStatementParser::class)->parse(bogWorkbook())['transactions'])[0];
    foreach (range(1, 30) as $index) {
        app(BankIngestionService::class)->ingest([new BankTransactionData([...$data->attributes, 'operation_id' => 'extra-'.$index])], 'api');
    }
    DB::flushQueryLog();
    Livewire::test(Bank::class)->assertViewHas('transactions', fn ($rows) => $rows->count() === 25 && $rows->total() === 32);
    expect($countQueries())->toBe($baseline)->toBeLessThanOrEqual(6);
    DB::disableQueryLog();
});

test('upload action preserves original name and stores statement privately', function () {
    $file = UploadedFile::fake()->createWithContent('Original BOG export.xlsx', file_get_contents(bogWorkbook()));
    Livewire::test(Bank::class)->call('mountAction', 'importStatement')->fillForm(['file' => $file])->callMountedAction()->assertHasNoErrors();
    $batch = BankImportBatch::sole();
    expect($batch->source_file)->toBe('Original BOG export.xlsx')->and($batch->stored_path)->toStartWith('bank-statements/');
    Storage::disk('local')->assertExists($batch->stored_path);
});

test('decimal parsing is deterministic and rejects unsafe precision', function () {
    $parser = app(BogStatementParser::class);
    foreach (['1,234.56', '1.234,56', '1 234,56', "1\u{00a0}234.56"] as $value) {
        expect($parser->money($value))->toBe('1234.56');
    }
    expect($parser->money('(12.50)'))->toBe('-12.50');
    expect(fn () => $parser->money('1234.567'))->toThrow(DomainException::class);
});

test('fallback fingerprints do not depend on DTO field order or import audit fields', function () {
    $attributes = ['transaction_date' => '2026-09-11 00:00:00', 'direction' => 'inflow', 'amount' => '123.45', 'currency' => 'GEL', 'description' => 'Deposit'];
    $first = new BankTransactionData($attributes);
    $second = new BankTransactionData(array_reverse($attributes, true));
    expect($first->fingerprint())->toBe($second->fingerprint());
    expect(app(BankIngestionService::class)->ingest([$first, $second], 'import'))->toBe(['imported_rows' => 1, 'duplicate_rows' => 1]);
});

test('missing account and inconsistent metadata never create a misleading balance', function () {
    $rows = bogRows();
    unset($rows[1]);
    $batch = importBog(array_values($rows));
    expect($batch->imported_rows)->toBe(2)->and($batch->reported_balance)->toBeNull()->and($batch->accounts)->toBe([]);
    $rows = bogRows();
    $rows[3][1] = '01.08.2026 - 31.08.2026';
    expect(fn () => importBog($rows))->toThrow(DomainException::class);
    expect(BankImportBatch::count())->toBe(1);
});

test('arbitrary ISO currencies retain their own totals without conversion', function () {
    $rows = bogRows();
    $rows[2][1] = $rows[8][6] = $rows[9][6] = 'EUR';
    importBog($rows);
    $total = app(BankReport::class)->totals(['currency' => 'EUR'])->sole();
    expect($total->currency)->toBe('EUR')->and((float) $total->inflow)->toBe(500.0)->and((float) $total->outflow)->toBe(50.0);
    Livewire::test(Bank::class)->set('currency', 'EUR')->assertSee('EUR')->assertViewHas('transactions', fn ($rows) => $rows->total() === 2);
});

test('chunked ingestion still skips duplicate IDs across chunk boundaries', function () {
    $rows = function () {
        foreach (range(1, 251) as $index) {
            yield new BankTransactionData(['operation_id' => 'chunk-'.$index, 'transaction_date' => '2026-09-11 00:00:00', 'direction' => 'inflow', 'amount' => '1.00', 'currency' => 'GEL']);
        }
        yield new BankTransactionData(['operation_id' => 'chunk-1', 'transaction_date' => '2026-09-11 00:00:00', 'direction' => 'inflow', 'amount' => '1.00', 'currency' => 'GEL']);
    };
    expect(app(BankIngestionService::class)->ingest($rows(), 'api'))->toBe(['imported_rows' => 251, 'duplicate_rows' => 1]);
});

test('xlsx upload tolerates alternate MIME detection and keeps a readable xlsx file', function (string $mime) {
    $file = UploadedFile::fake()->createWithContent('Bank Statement.XLSX', file_get_contents(bogWorkbook()))->mimeType($mime);
    Livewire::test(Bank::class)->call('mountAction', 'importStatement')->fillForm(['file' => $file])->callMountedAction()->assertHasNoErrors();
    $batch = BankImportBatch::sole();
    expect($batch->imported_rows)->toBe(2)->and($batch->stored_path)->toEndWith('.xlsx');
    Storage::disk('local')->assertExists($batch->stored_path);
})->with(['application/zip', 'application/octet-stream', 'application/vnd.ms-excel']);

test('CSV uploads use the same normalized import with delimiter detection and Georgian text', function (string $delimiter, string $mime) {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "\xEF\xBB\xBF");
    foreach (bogRows() as $row) {
        fputcsv($stream, array_map(fn ($value) => $value instanceof DateTimeInterface ? $value->format('d.m.Y') : $value, $row), $delimiter, '"', '');
    }
    rewind($stream);
    $file = UploadedFile::fake()->createWithContent('Statement.csv', stream_get_contents($stream))->mimeType($mime);
    fclose($stream);
    Livewire::test(Bank::class)->call('mountAction', 'importStatement')->fillForm(['file' => $file])->callMountedAction()->assertHasNoErrors();
    $batch = BankImportBatch::sole();
    expect($batch->imported_rows)->toBe(2)->and($batch->stored_path)->toEndWith('.csv');
    expect(BankTransaction::where('operation_id', '000123')->sole()->counterparty_name)->toBe('ქართული კომპანია');
})->with([[',', 'text/csv'], [';', 'application/csv'], ["\t", 'text/plain']]);

test('spreadsheet MIME aliases do not permit unsupported or executable extensions', function (string $extension) {
    $file = UploadedFile::fake()->createWithContent('statement.'.$extension, file_get_contents(bogWorkbook()))->mimeType('application/zip');
    Livewire::test(Bank::class)->call('mountAction', 'importStatement')->fillForm(['file' => $file])->callMountedAction()->assertHasFormErrors();
    expect(BankImportBatch::count())->toBe(0);
})->with(['php', 'xls', 'zip']);

test('Livewire signed temporary upload endpoint accepts a small real workbook', function () {
    Storage::fake('tmp-for-tests'); // Livewire uses this disk only inside its test environment.
    $file = UploadedFile::fake()->createWithContent('small.xlsx', file_get_contents(bogWorkbook()));
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5));
    $this->post($url, ['files' => [$file]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'paths');
});

test('Windows artisan serve preserves temporary directory variables before PHP request startup', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Windows development server regression.');
    }
    $command = new ServeCommand;
    $method = new ReflectionMethod($command, 'shouldPassThroughEnvironmentVariable');
    foreach (['TEMP', 'TMP', 'TMPDIR', 'Temp', 'Tmp'] as $key) {
        expect($method->invoke($command, $key))->toBeTrue();
    }
});

test('Statement of Account recognizes original BOG columns after metadata and preserves one-sided movements', function (int $headerRow, string $idHeader) {
    $rows = [
        ['Statement of Account'],
        ['ანგარიში', 'GE00BG0000000000000000'],
        ['ვალუტა', 'GEL'],
        ['პერიოდი', '01.09.2026 - 11.09.2026'],
        ['საწყისი ნაშთი', '1000.00'],
        ['საბოლოო ნაშთი', '1450.00'],
    ];
    while (count($rows) < $headerRow - 1) {
        $rows[] = [];
    }
    $rows[] = ["  თარიღი\u{00a0}", 'საბუთის N', 'მოკორესპოდენტო ანგარიში', 'დებეტი', 'კრედიტი', 'ოპერაციის შინაარსი', 'ოპერაციის ტიპი', $idHeader, ' Ref ', 'თანხა', 'ნაშთი ოპერაციის ბოლოს', 'კონვერტაციის თანხა', 'კონვერტაციის ვალუტა'];
    $rows[] = ['10.09.2026', 'DOC-1', 'GE00COUNTERPARTY1', null, 500, 'ბარათის ჩარიცხვა', 'PBS', '000123', 'ref-1', -999, 1500, 100, 'USD'];
    $rows[] = ['11.09.2026', 'DOC-2', 'GE00COUNTERPARTY2', 50, " \u{00a0} ", 'მასალების შეძენა', 'TRN', '000124', 'ref-2', 999, 1450, null, null];
    $path = bogWorkbook($rows, 'Statement of Account');
    $originalHash = hash_file('sha256', $path);
    $service = app(BankStatementImportService::class);
    $batch = $service->import($path, 'BOG original.xlsx');
    expect($batch->errors)->toBe([])->and($batch->imported_rows)->toBe(2)->and($batch->rejected_rows)->toBe(0)
        ->and($batch->opening_balance)->toBe('1000.00')->and($batch->closing_balance)->toBe('1450.00')
        ->and($batch->period_from->toDateString())->toBe('2026-09-01')->and($batch->period_to->toDateString())->toBe('2026-09-11');
    $inflow = BankTransaction::where('operation_id', '000123')->sole();
    $outflow = BankTransaction::where('operation_id', '000124')->sole();
    expect($inflow->direction)->toBe('inflow')->and($inflow->amount)->toBe('500.00')->and($inflow->currency)->toBe('GEL')
        ->and($inflow->counterparty_account)->toBe('GE00COUNTERPARTY1')->and($inflow->description)->toBe('ბარათის ჩარიცხვა')
        ->and($inflow->balance_after)->toBe('1500.00')->and($inflow->reference)->toBe('ref-1')
        ->and($inflow->raw_data['sheet'])->toBe('Statement of Account')->and($inflow->raw_data['row'])->toBe($headerRow + 1)
        ->and($inflow->raw_data['cells'][1]['value'])->toBe('DOC-1')->and($inflow->raw_data['cells'][3]['value'])->toBe('')
        ->and((float) $inflow->raw_data['cells'][4]['value'])->toBe(500.0)->and((float) $inflow->raw_data['cells'][9]['value'])->toBe(-999.0);
    expect($outflow->direction)->toBe('outflow')->and($outflow->amount)->toBe('50.00')->and($outflow->description)->toBe('მასალების შეძენა')
        ->and($outflow->balance_after)->toBe('1450.00')->and($outflow->raw_data['cells'][4]['value'])->toBe(" \u{00a0} ");
    expect(hash_file('sha256', $path))->toBe($originalHash);
    expect($service->import($path, 'same BOG.xlsx')->duplicate_rows)->toBe(2);
})->with([[13, 'ოპერაციის იდ'], [13, " ოპერაციის   ID \t"], [50, 'ოპერაციის იდ']]);

test('date debit and credit headers do not require bank branding or optional identifiers', function () {
    $batch = importBog([
        ['ვალუტა', 'GEL'],
        ['თარიღი', 'დებეტი', 'კრედიტი'],
        ['11.09.2026', 25, null],
    ]);
    expect($batch->imported_rows)->toBe(1)->and(BankTransaction::sole()->amount)->toBe('25.00');
});

test('BOG transaction columns are never replaced by later turnover columns across all 82 rows', function () {
    $headers = ['თარიღი', 'საბუთის N', 'მოკორესპოდენტო ანგარიში', 'დებეტი', 'კრედიტი', 'ოპერაციის შინაარსი', 'ოპერაციის ტიპი', 'ოპერაციის იდ', 'Ref'];
    $headers = array_pad($headers, 32, '');
    $headers[19] = 'დანიშნულება';
    $headers[21] = 'თანხა';
    $headers[22] = 'ბრუნვა დებეტი';
    $headers[23] = 'ბრუნვა კრედიტი';
    $headers[24] = 'ნაშთი დღის ბოლოს';
    $headers[25] = 'ნაშთი ოპერაციის ბოლოს';
    $headers[28] = 'კონვერტაციის თანხა';
    $headers[29] = 'კონვერტაციის ვალუტა';
    $parser = app(BogStatementParser::class);
    $mapping = (new ReflectionMethod($parser, 'headerMap'))->invoke($parser, $headers);
    expect(array_intersect_key($mapping, array_flip(['transaction_date', 'debit', 'credit', 'operation_type', 'operation_id', 'reference'])))->toBe([
        'transaction_date' => 0, 'debit' => 3, 'credit' => 4, 'operation_type' => 6, 'operation_id' => 7, 'reference' => 8,
    ]);
    $rows = [
        ['Statement of Account'], ['ანგარიში', 'GE00FIXTURE'], ['ვალუტა', 'GEL'],
        ['პერიოდი', '01.09.2026 - 11.09.2026'], ['საწყისი ნაშთი', 1000], ['საბოლოო ნაშთი', 1074.90],
    ];
    $rows = array_pad($rows, 12, []);
    $rows[] = $headers;
    $movements = [
        [null, 147.90, 'TRN', '124773207693'],
        [103, null, 'PMD', '124773238958'],
        [1, null, 'COM', '124773238959'],
    ];
    // Sanitized remaining rows retain the full statement's 56/26 direction split.
    foreach (range(1, 79) as $index) {
        $movements[] = $index <= 55 ? [null, 1, 'TRN', 'fixture-'.$index] : [1, null, 'PMD', 'fixture-'.$index];
    }
    foreach ($movements as $index => [$debit, $credit, $type, $id]) {
        $row = array_fill(0, 32, null);
        [$row[0], $row[3], $row[4], $row[5], $row[6], $row[7], $row[8]] = ['11.09.2026', $debit, $credit, 'Transaction description', $type, $id, 'ref-'.$index];
        $row[19] = 'Later detail';
        $row[21] = -9999;
        $row[22] = 2569.70;
        $row[23] = 1507.94;
        $row[28] = 777;
        $row[29] = 'USD';
        $rows[] = $row;
    }
    expect($rows)->toHaveCount(95);
    $path = bogWorkbook($rows, 'Statement of Account');
    $originalHash = hash_file('sha256', $path);
    $batch = app(BankStatementImportService::class)->import($path, 'BOG structural fixture.xlsx');
    expect($batch->imported_rows)->toBe(82)->and($batch->rejected_rows)->toBe(0)->and($batch->errors)->toBe([])
        ->and(BankTransaction::where('direction', 'inflow')->count())->toBe(56)
        ->and(BankTransaction::where('direction', 'outflow')->count())->toBe(26);
    foreach ([['124773207693', 14, 'inflow', '147.90'], ['124773238958', 15, 'outflow', '103.00'], ['124773238959', 16, 'outflow', '1.00']] as [$id, $row, $direction, $amount]) {
        $record = BankTransaction::where('operation_id', $id)->sole();
        expect($record->direction)->toBe($direction)->and($record->amount)->toBe($amount)
            ->and($record->description)->toBe('Transaction description')->and($record->raw_data['row'])->toBe($row);
    }
    expect(hash_file('sha256', $path))->toBe($originalHash);
});

test('turnover-only columns cannot be recognized as transaction debit and credit', function () {
    expect(fn () => importBog([
        ['BOG'], ['ვალუტა', 'GEL'],
        ['თარიღი', 'ბრუნვა დებეტი', 'ბრუნვა კრედიტი', 'ოპერაციის ტიპი', 'ოპერაციის იდ'],
        ['11.09.2026', 103, null, 'PMD', 'test'],
    ]))->toThrow(DomainException::class);
});

test('owner confirms a batch rollback without affecting same-date same-file imports or categories', function () {
    $original = importBog();
    $rows = bogRows();
    $rows[8][1] = 'other-operation';
    $rows[9][2] = 'other-reference';
    $other = importBog($rows); // Same filename, account, currency and period; different batch ownership.
    $otherBefore = $other->fresh()->toArray();
    $otherRows = $other->transactions()->get()->toArray();
    $categories = BankCategory::all()->toArray();
    $page = Livewire::test(Bank::class)->set('showHistory', true)->call('showBatch', $original->id)
        ->call('mountAction', 'rollbackImport', ['batch' => $original->id])
        ->assertMountedActionModalSee(['original BOG.xlsx', '01.09.2026', '11.09.2026', '2']);
    expect($original->transactions()->count())->toBe(2); // Merely opening confirmation never deletes.
    $page->callMountedAction()->assertHasNoErrors()->assertSee(__('bank.rolled_back'));
    expect($original->transactions()->count())->toBe(0)->and($original->fresh()->rolled_back_at)->not->toBeNull()
        ->and($original->fresh()->rolled_back_by)->toBe(auth()->id())->and($original->fresh()->rolled_back_rows)->toBe(2)
        ->and($other->fresh()->toArray())->toBe($otherBefore)->and($other->transactions()->get()->toArray())->toBe($otherRows)
        ->and(BankCategory::all()->toArray())->toBe($categories);
    expect(app(BankImportRollbackService::class)->rollback($original->id, auth()->user()))->toBe(0);
    $replacement = importBog();
    expect($replacement->imported_rows)->toBe(2)->and($replacement->duplicate_rows)->toBe(0)->and($replacement->rejected_rows)->toBe(0);
});

test('rollback excludes old balance snapshots and prevents ingestion into a rolled back batch', function () {
    $batch = importBog();
    $data = iterator_to_array(app(BogStatementParser::class)->parse(bogWorkbook())['transactions'])[0];
    expect(app(BankReport::class)->balances())->toHaveCount(1);
    app(BankImportRollbackService::class)->rollback($batch->id, auth()->user());
    expect(app(BankReport::class)->balances())->toBeEmpty();
    expect(fn () => app(BankIngestionService::class)->ingest([$data], 'import', $batch->id))->toThrow(ModelNotFoundException::class);
    expect(BankTransaction::count())->toBe(0);
});

test('non-owner cannot invoke import rollback service', function () {
    $batch = importBog();
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    expect(fn () => app(BankImportRollbackService::class)->rollback($batch->id, $admin))->toThrow(HttpException::class);
    expect($batch->transactions()->count())->toBe(2)->and($batch->fresh()->rolled_back_at)->toBeNull();
});

test('failure to record rollback audit restores deleted transactions atomically', function () {
    $batch = importBog();
    DB::statement("CREATE TRIGGER fail_bank_rollback BEFORE UPDATE ON bank_import_batches WHEN NEW.rolled_back_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
    expect(fn () => app(BankImportRollbackService::class)->rollback($batch->id, auth()->user()))->toThrow(QueryException::class);
    expect($batch->transactions()->count())->toBe(2)->and($batch->fresh()->rolled_back_at)->toBeNull();
});
