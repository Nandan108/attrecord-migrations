<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;

/**
 * Canonical hash of a desired model set (arch-migrations.md §5.3's fast path): a consumer stores
 * the last-converged fingerprint (its own option, or the ledger's) and skips even `plan()`'s
 * introspection when the running code's fingerprint matches.
 *
 * The hash input is the dialect's own `buildCreateTable()` output for every class, in the given
 * order — already deterministic and canonical (the same rendering authority as everything else),
 * so any attribute change that could affect the schema changes the fingerprint.
 */
final class Fingerprint
{
    /** @param list<class-string<Record>> $recordClasses */
    public static function of(SqlDialect $dialect, array $recordClasses): string
    {
        $ddl = [];
        foreach ($recordClasses as $class) {
            $ddl[] = $dialect->buildCreateTable(TableSchema::fromClass($class));
        }

        return hash('sha256', implode(";\n", $ddl));
    }
}
