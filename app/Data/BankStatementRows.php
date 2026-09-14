<?php

namespace App\Data;

use Countable;
use Generator;
use IteratorAggregate;
use SplTempFileObject;

/** Replayable normalized rows, spilling to an automatically removed temp file above 2 MB. */
final class BankStatementRows implements Countable, IteratorAggregate
{
    private SplTempFileObject $file;

    private int $count = 0;

    public function __construct()
    {
        $this->file = new SplTempFileObject(2 * 1024 * 1024);
    }

    public function append(BankTransactionData $data): void
    {
        $this->file->fwrite(json_encode($data->attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->count++;
    }

    /** @return Generator<BankTransactionData> */
    public function getIterator(): Generator
    {
        $this->file->rewind();
        while (! $this->file->eof()) {
            $line = $this->file->fgets();
            if (trim($line) !== '') {
                yield new BankTransactionData(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }

    public function count(): int
    {
        return $this->count;
    }
}
