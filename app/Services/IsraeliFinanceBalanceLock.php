<?php

namespace App\Services;

use App\Models\PatientGroup;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/** One existing row serializes every Israeli account/currency, including multi-leg operations. */
final class IsraeliFinanceBalanceLock
{
    public static function transaction(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            self::acquire();

            return $operation();
        }, 3);
    }

    /** Acquire before payment/movement row locks and before reading any available balance. */
    public static function acquire(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('The Israeli balance lock requires an active transaction.');
        }
        // SQLite regression tests use a single in-memory connection. PostgreSQL is the production protocol.
        if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            return;
        }
        if ($connection->getDriverName() !== 'pgsql'
            || $connection->selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
            throw new LogicException('Israeli balance operations require PostgreSQL READ COMMITTED isolation.');
        }
        // Reuse the anchor already locked by doctor, employee and technician salary funding.
        // FOR UPDATE is held until the OUTERMOST transaction commits/rolls back. No PHP lock cache.
        if (! $connection->table('patient_groups')->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)
            ->lockForUpdate()->first(['id'])) {
            throw new LogicException('The Israeli balance lock anchor is missing.');
        }
    }
}
