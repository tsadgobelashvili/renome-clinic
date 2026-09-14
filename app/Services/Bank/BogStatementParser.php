<?php

namespace App\Services\Bank;

use App\Data\BankStatementRows;
use App\Data\BankTransactionData;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/** Reads the original workbook without modifying it. No descriptions/code-based accounting inference. */
class BogStatementParser
{
    private bool $excel1904 = false;

    private const HEADERS = [
        'transaction_date' => ['date', 'transaction date', 'operation date', 'თარიღი', 'ოპერაციის თარიღი', 'ტრანზაქციის თარიღი'],
        'value_date' => ['value date', 'valuation date', 'ვალუტირების თარიღი'],
        'operation_id' => ['operation id', 'transaction id', 'ოპერაციის ნომერი', 'ოპერაციის id', 'ოპერაციის იდ', 'ტრანზაქციის ნომერი', 'ოპერაციის იდენტიფიკატორი'],
        'reference' => ['ref', 'reference', 'რეფერენსი', 'რეფერენსი ნომერი'],
        'operation_type' => ['operation type', 'type', 'ოპერაციის ტიპი', 'ოპერაციის კოდი'],
        // Turnover/summary columns are not transaction debit and credit amounts.
        'debit' => ['debit', 'debit amount', 'withdrawal', 'outflow', 'დებეტი', 'გასავალი', 'გასული თანხა'],
        'credit' => ['credit', 'credit amount', 'deposit', 'inflow', 'კრედიტი', 'შემოსავალი', 'შემოსული თანხა'],
        'currency' => ['currency', 'ccy', 'ვალუტა'],
        'account_identifier' => ['account', 'account number', 'account no', 'iban', 'ანგარიში', 'ანგარიშის ნომერი'],
        'counterparty_name' => ['counterparty', 'counterparty name', 'beneficiary', 'beneficiary name', 'პარტნიორი', 'კონტრაგენტი', 'მიმღები', 'გამგზავნი მიმღები', 'პარტნიორის დასახელება'],
        'counterparty_account' => ['counterparty account', 'beneficiary account', 'პარტნიორის ანგარიში', 'კონტრაგენტის ანგარიში', 'მიმღების ანგარიში', 'მოკორესპოდენტო ანგარიში'],
        'description' => ['description', 'purpose', 'payment purpose', 'details', 'დანიშნულება', 'აღწერა', 'დეტალები', 'გადახდის დანიშნულება', 'ოპერაციის შინაარსი'],
        'bank_fee' => ['commission', 'bank fee', 'fee', 'საკომისიო', 'ბანკის საკომისიო'],
        'gross_amount' => ['gross amount', 'gross', 'მთლიანი თანხა'],
        'balance_after' => ['balance', 'running balance', 'balance after', 'ნაშთი', 'მიმდინარე ნაშთი', 'საბოლოო ნაშთი', 'ნაშთი ოპერაციის ბოლოს'],
    ];

    private const METADATA = [
        'account_identifier' => ['account', 'account number', 'iban', 'ანგარიში', 'ანგარიშის ნომერი'],
        'currency' => ['currency', 'ccy', 'ვალუტა'],
        'opening_balance' => ['opening balance', 'initial balance', 'საწყისი ნაშთი'],
        'closing_balance' => ['closing balance', 'final balance', 'საბოლოო ნაშთი'],
        'period_from' => ['period from', 'start date', 'from date', 'საწყისი თარიღი', 'პერიოდის დასაწყისი'],
        'period_to' => ['period to', 'end date', 'to date', 'საბოლოო თარიღი', 'პერიოდის დასასრული'],
    ];

    public function parse(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'csv'], true) || ! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            throw new DomainException(__('bank.xlsx_only'));
        }
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        if ($extension === 'xlsx') {
            $this->validateArchive($path);
            $reader = new Reader($options);
        } else {
            $reader = $this->csvReader($path);
        }
        $transactions = new BankStatementRows;
        $errors = $metadata = [];
        $recognized = false;
        $rowCount = 0;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $this->excel1904 = $options->SHOULD_USE_1904_DATES;
                $map = null;
                $headers = [];
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    if (++$rowCount > 50000) {
                        throw new DomainException(__('bank.too_many_rows'));
                    }
                    $values = $row->toArray();
                    if (! array_filter($values, fn ($value) => $value !== null && $value !== '')) {
                        continue;
                    }
                    $candidate = $this->headerMap($values);
                    // Scan the sheet, including headers below long metadata/title sections.
                    // Branding and operation identifiers are optional, not recognition gates.
                    if (isset($candidate['transaction_date'], $candidate['debit'], $candidate['credit'])) {
                        $map = $candidate;
                        $headers = array_map(fn ($value) => $this->text($value) ?? '', $values);
                        $recognized = true;

                        continue;
                    }
                    if ($map === null || $this->isSummary($values)) {
                        $this->captureMetadata($values, $metadata);

                        continue;
                    }
                    $data = [];
                    foreach (self::HEADERS as $field => $aliases) {
                        $data[$field] = isset($map[$field]) ? ($values[$map[$field]] ?? null) : null;
                    }
                    try {
                        $debit = $this->money($data['debit']);
                        $credit = $this->money($data['credit']);
                        $hasDebit = $debit !== null && $debit > 0;
                        $hasCredit = $credit !== null && $credit > 0;
                        if ($hasDebit === $hasCredit) {
                            throw new DomainException(__('bank.invalid_direction'));
                        }
                        $normalized = [];
                        foreach (['operation_id', 'reference', 'operation_type', 'counterparty_name', 'counterparty_account', 'description'] as $field) {
                            $normalized[$field] = $this->text($data[$field]);
                        }
                        $raw = [];
                        foreach ($values as $index => $value) {
                            $raw[] = ['column' => $index + 1, 'header' => $headers[$index] ?? '', 'value' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value];
                        }
                        $transactions->append(new BankTransactionData([
                            ...$normalized,
                            'transaction_date' => $this->date($data['transaction_date']),
                            'value_date' => $this->text($data['value_date']) === null ? null : substr($this->date($data['value_date']), 0, 10),
                            'direction' => $hasCredit ? 'inflow' : 'outflow',
                            'amount' => $hasCredit ? $credit : $debit,
                            'currency' => strtoupper($this->text($data['currency']) ?? $metadata['currency'] ?? ''),
                            'account_identifier' => $this->account($this->text($data['account_identifier']) ?? $metadata['account_identifier'] ?? null),
                            'bank_fee' => $this->money($data['bank_fee']) ?? '0.00',
                            'gross_amount' => $this->money($data['gross_amount']),
                            'balance_after' => $this->money($data['balance_after']),
                            'raw_data' => ['sheet' => $sheet->getName(), 'row' => $rowNumber, 'cells' => $raw],
                        ]));
                    } catch (DomainException|ValidationException $exception) {
                        $errors[] = ['sheet' => $sheet->getName(), 'row' => $rowNumber, 'message' => $exception->getMessage()];
                    }
                }
            }
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(__('bank.invalid_file'), previous: $exception);
        } finally {
            $reader->close();
        }
        if (! $recognized || count($transactions) === 0) {
            throw new DomainException(__('bank.unrecognized').($errors ? ' '.$errors[0]['message'] : ''));
        }
        $accounts = $currencies = [];
        $missingAccount = false;
        $minDate = $maxDate = null;
        foreach ($transactions as $transaction) {
            $data = $transaction->attributes;
            $missingAccount = $missingAccount || $data['account_identifier'] === null;
            if ($data['account_identifier'] !== null) {
                $accounts[$data['account_identifier']] = $data['account_identifier'];
            }
            $currencies[$data['currency']] = $data['currency'];
            $minDate = $minDate === null ? $data['transaction_date'] : min($minDate, $data['transaction_date']);
            $maxDate = $maxDate === null ? $data['transaction_date'] : max($maxDate, $data['transaction_date']);
        }
        $accounts = array_values($accounts);
        $currencies = array_values($currencies);
        $singleAccount = count($accounts) === 1 && ! $missingAccount;
        $singleCurrency = count($currencies) === 1;
        $metadata['period_from'] ??= substr($minDate, 0, 10);
        // Do not pretend the last transaction date is a statement closing-balance date.
        $explicitEnd = $metadata['period_to'] ?? null;
        $metadata['period_to'] ??= substr($maxDate, 0, 10);
        if ($metadata['period_from'] > $metadata['period_to'] || $metadata['period_from'] > substr($minDate, 0, 10) || $metadata['period_to'] < substr($maxDate, 0, 10)) {
            throw new DomainException(__('bank.invalid_date'));
        }
        $metadata['account_identifier'] = $singleAccount ? $accounts[0] : null;
        $metadata['currency'] = $singleCurrency ? $currencies[0] : null;
        $metadata['accounts'] = $accounts;
        $metadata['currencies'] = $currencies;
        $metadata['reported_balance'] = null;
        $metadata['balance_as_of'] = null;
        $metadata['balance_origin'] = null;
        if ($singleAccount && $singleCurrency) {
            if (isset($metadata['closing_balance']) && $explicitEnd !== null) {
                $metadata['reported_balance'] = $metadata['closing_balance'];
                $metadata['balance_as_of'] = $explicitEnd.' 23:59:59';
                $metadata['balance_origin'] = 'closing_balance';
            } elseif ($errors === []) {
                $last = $this->latestReliableBalance($transactions);
                if ($last !== null) {
                    $metadata['reported_balance'] = $last['balance_after'];
                    $metadata['balance_as_of'] = $last['transaction_date'];
                    $metadata['balance_origin'] = 'balance_after';
                }
            }
        } else {
            // A summary total without a unique account/currency cannot establish a balance.
            unset($metadata['opening_balance'], $metadata['closing_balance']);
        }

        return ['transactions' => $transactions, 'metadata' => $metadata, 'errors' => $errors];
    }

    private function headerMap(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            $normalized = $this->normalize($this->text($value) ?? '');
            foreach (self::HEADERS as $field => $aliases) {
                // Bilingual headers commonly separate the two labels with a newline or slash.
                if (in_array($normalized, $aliases, true) || collect(preg_split('/[\r\n\/]+/u', $this->text($value) ?? ''))->contains(fn ($part) => in_array($this->normalize($part), $aliases, true))) {
                    // Preserve the first exact alias match; later detail columns must not replace it.
                    $map[$field] ??= $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function captureMetadata(array $values, array &$metadata): void
    {
        foreach ($values as $index => $value) {
            $parts = preg_split('/[:：]/u', $this->text($value) ?? '', 2);
            $label = $this->normalize($parts[0]);
            foreach (self::METADATA as $field => $aliases) {
                if (! in_array($label, $aliases, true)) {
                    continue;
                }
                $next = $parts[1] ?? null;
                if ($this->text($next) === null) {
                    foreach (array_slice($values, $index + 1) as $cell) {
                        if ($this->text($cell) !== null) {
                            $next = $cell;
                            break;
                        }
                    }
                }
                if ($this->text($next) === null) {
                    continue;
                }
                $parsed = match ($field) {
                    'opening_balance', 'closing_balance' => $this->money($next),
                    'period_from', 'period_to' => substr($this->date($next), 0, 10),
                    'currency' => strtoupper($this->text($next)),
                    default => $this->account($next),
                };
                if (isset($metadata[$field]) && $metadata[$field] !== $parsed) {
                    throw new DomainException(__('bank.multiple_statements'));
                }
                $metadata[$field] = $parsed;
            }
            // Common combined period line, e.g. 01.09.2026 - 30.09.2026.
            if (in_array($label, ['period', 'statement period', 'პერიოდი', 'ამონაწერის პერიოდი'], true)) {
                $period = implode(' ', array_map(fn ($v) => $this->text($v) ?? '', array_slice($values, $index)));
                if (preg_match_all('/\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2}/', $period, $matches) && count($matches[0]) === 2) {
                    $metadata['period_from'] = substr($this->date($matches[0][0]), 0, 10);
                    $metadata['period_to'] = substr($this->date($matches[0][1]), 0, 10);
                }
            }
        }
    }

    private function isSummary(array $values): bool
    {
        $first = $this->normalize($this->text(collect($values)->first(fn ($v) => $v !== null && $v !== '')) ?? '');
        $label = explode(':', $first)[0];

        return in_array($label, ['total', 'totals', 'turnover', 'ჯამი', 'სულ', 'ბრუნვა', ...array_merge(...array_values(self::METADATA))], true);
    }

    private function latestReliableBalance(iterable $transactions): ?array
    {
        // Validate running balances to establish ascending/descending order, including same-day rows.
        $ascending = $descending = true;
        $first = $previous = null;
        foreach ($transactions as $transaction) {
            $row = $transaction->attributes;
            if ($row['balance_after'] === null) {
                return null;
            }
            $first ??= $row;
            if ($previous !== null) {
                $delta = $this->cents($row['balance_after']) - $this->cents($previous['balance_after']);
                $ascending = $ascending && $row['transaction_date'] >= $previous['transaction_date']
                    && $delta === $this->cents($row['amount']) * ($row['direction'] === 'inflow' ? 1 : -1);
                $descending = $descending && $row['transaction_date'] <= $previous['transaction_date']
                    && -$delta === $this->cents($previous['amount']) * ($previous['direction'] === 'inflow' ? 1 : -1);
            }
            $previous = $row;
        }

        return $ascending ? $previous : ($descending ? $first : null);
    }

    private function cents(string $value): int
    {
        return (int) str_replace('.', '', $value);
    }

    public function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (is_float($value)) {
            if (! is_finite($value) || abs($value) >= 1e13 || abs($value * 100 - round($value * 100)) > 0.01) {
                throw new DomainException(__('bank.invalid_number'));
            }

            return number_format($value, 2, '.', '');
        }
        $number = preg_replace('/[\s\x{00a0}\x{202f}]/u', '', (string) $value);
        if ($number === '' || $number === '-') {
            return null;
        }
        if (preg_match('/^\(.*\)$/', $number)) {
            $number = '-'.substr($number, 1, -1);
        }
        if (str_contains($number, ',') && str_contains($number, '.')) {
            if (preg_match('/^-?\d{1,3}(,\d{3})+\.\d{1,2}$/', $number)) {
                $number = str_replace(',', '', $number);
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+,\d{1,2}$/', $number)) {
                $number = str_replace(',', '.', str_replace('.', '', $number));
            }
        } elseif (str_contains($number, ',')) {
            $number = preg_match('/^-?\d{1,3}(,\d{3})+$/', $number) ? str_replace(',', '', $number) : str_replace(',', '.', $number);
        }
        if (! preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', $number, $matches)) {
            throw new DomainException(__('bank.invalid_number'));
        }
        $integer = ltrim($matches[2], '0') ?: '0';
        $fraction = str_pad($matches[3] ?? '', 2, '0');

        return ($integer === '0' && $fraction === '00' ? '' : $matches[1]).$integer.'.'.$fraction;
    }

    public function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        // Some original exports contain numeric Excel serials without a date style.
        // The reader detects the workbook calendar (1900/1904); never treat text IDs as dates.
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 20000 && $value <= 80000) {
            return (new DateTimeImmutable($this->excel1904 ? '1904-01-01' : '1899-12-30'))
                ->modify('+'.(int) round($value * 86400).' seconds')->format('Y-m-d H:i:s');
        }
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim((string) $value));
            if ($date && $date->format($format) === trim((string) $value)) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new DomainException(__('bank.invalid_date'));
    }

    private function account(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : strtoupper(preg_replace('/\s+/u', '', $text));
    }

    private function text(mixed $value): ?string
    {
        $text = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(['_', '.', '(', ')'], [' ', '', ' ', ' '], $value))));
    }

    private function validateArchive(string $path): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new DomainException(__('bank.invalid_file'));
        }
        try {
            $size = 0;
            if ($zip->numFiles > 5000 || $zip->locateName('xl/workbook.xml') === false) {
                throw new DomainException(__('bank.invalid_file'));
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $size += $zip->statIndex($index)['size'];
                if ($size > 100 * 1024 * 1024) {
                    throw new DomainException(__('bank.too_many_rows'));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function csvReader(string $path): CsvReader
    {
        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        // Header detection uses the same aliases and normalized transaction pipeline.
        // Inspect leading rows rather than trusting a delimiter in the title line.
        foreach ([',', ';', "\t"] as $delimiter) {
            $handle = fopen($path, 'rb');
            try {
                for ($index = 0; $index < 200 && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $index++) {
                    $row = array_map(fn ($value) => ltrim($value ?? '', "\xEF\xBB\xBF"), $row);
                    $map = $this->headerMap($row);
                    if (isset($map['transaction_date'], $map['debit'], $map['credit'])) {
                        $options->FIELD_DELIMITER = $delimiter;

                        return new CsvReader($options);
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        throw new DomainException(__('bank.unrecognized'));
    }
}
