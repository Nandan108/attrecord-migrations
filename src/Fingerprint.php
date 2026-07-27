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
 * The hash input is the dialect's own `buildCreateTable()` output for every table, in the given
 * order — already deterministic and canonical (the same rendering authority as everything else),
 * so any change that could affect the schema changes the fingerprint. That includes columns a
 * class never declared: a schema built by {@see TableSchema::extendedWith()} hashes like any
 * other, so a consumer whose table shape depends on runtime data gets a fingerprint that moves
 * when that data does.
 */
final class Fingerprint
{
    /** @param list<TableSchema> $schemas */
    public static function of(SqlDialect $dialect, array $schemas): string
    {
        $ddl = [];
        foreach ($schemas as $schema) {
            $ddl[] = $dialect->buildCreateTable($schema);
        }

        return hash('sha256', implode(";\n", $ddl));
    }

    /**
     * Convenience for the common all-classes case.
     *
     * @param list<class-string<Record>> $recordClasses
     */
    public static function ofClasses(SqlDialect $dialect, array $recordClasses): string
    {
        return self::of($dialect, array_map(
            static fn (string $c): TableSchema => TableSchema::fromClass($c),
            $recordClasses,
        ));
    }
}
