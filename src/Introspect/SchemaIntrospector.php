<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Introspect;

use Nandan108\Attrecord\DbSession;
use Nandan108\AttrecordMigrations\Live\LiveTable;

/**
 * Reads one table's live definition out of the engine's catalog
 * (`information_schema` / `pg_catalog` / `PRAGMA`) into the {@see LiveTable} model.
 *
 * Implementations are read-only and side-effect free: `plan()`'s purity rests on that
 * (arch-migrations.md §3).
 */
interface SchemaIntrospector
{
    /**
     * @param string $tableName full (prefixed) table name, exactly as it exists in the database
     *
     * @return LiveTable|null null when the table does not exist
     */
    public function introspectTable(DbSession $session, string $tableName): ?LiveTable;
}
