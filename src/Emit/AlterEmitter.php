<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Emit;

use Nandan108\Attrecord\Schema\CheckDefinition;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;

/**
 * Renders the dialect-specific ALTER statements for one planned change. All column-level fragments
 * come from the attrecord dialect's public builders ({@see \Nandan108\Attrecord\SqlDialect}), so a
 * column renders identically in CREATE and in ALTER — the emitters only own the ALTER *wrappers*.
 *
 * Emitters never decide *whether* a change should happen or how it is classified — that is the
 * differ's job. They answer "what SQL would do it here", or `null` for "this engine cannot express
 * it as an ALTER" (SQLite column modification), which the differ classifies Manual.
 */
interface AlterEmitter
{
    /** @return list<string> */
    public function addColumn(string $table, ColumnDefinition $col): array;

    /**
     * Statements bringing a live column to the desired definition, or null when the engine has no
     * in-place column modification (SQLite — the §4.4 rebuild boundary, Manual in v0.1).
     *
     * @param list<string> $facets the differing tuple facets, so engines with granular ALTERs (PG) touch only what drifted
     *
     * @return list<string>|null
     */
    public function modifyColumn(string $table, ColumnDefinition $col, ColumnTuple $desired, array $facets): ?array;

    /** @return list<string> */
    public function renameColumn(string $table, string $oldName, ColumnDefinition $col): array;

    /** @return list<string> */
    public function dropColumn(string $table, string $column): array;

    /** @param list<string> $columns
     * @return list<string> */
    public function createIndex(string $table, string $name, array $columns, bool $unique): array;

    /** @return list<string> */
    public function dropIndex(string $table, string $name): array;

    /** @return list<string> */
    public function addForeignKey(string $table, ForeignKeyDefinition $fk): array;

    /** @return list<string>|null null when the engine cannot drop an FK in place (SQLite) */
    public function dropForeignKey(string $table, string $name): ?array;

    /**
     * Add a table-level CHECK constraint, or null when the engine cannot (SQLite — table rebuild).
     *
     * The constraint fragment comes from the attrecord dialect, so it renders identically here and
     * in CREATE TABLE.
     *
     * @return list<string>|null
     */
    public function addCheck(string $table, CheckDefinition $check): ?array;

    /** @return list<string>|null null when the engine cannot drop a CHECK in place (SQLite) */
    public function dropCheck(string $table, string $name): ?array;

    /**
     * Rename a constraint in place, or null when the engine has no such operation.
     *
     * Only PostgreSQL does (`ALTER TABLE … RENAME CONSTRAINT`), and there it is a metadata-only
     * catalogue update — instant, no row validation, no rewrite. MySQL and MariaDB have no
     * equivalent, so the differ falls back to add-then-drop, which is a very different cost: `ADD
     * FOREIGN KEY` validates every existing row under a metadata lock. That difference is why a
     * rename is classified per dialect rather than globally.
     *
     * @return list<string>|null
     */
    public function renameForeignKey(string $table, string $from, string $to): ?array;

    /**
     * Reason this column cannot be ADDed on this engine, or null when it can. (SQLite rejects
     * ADD COLUMN with a non-constant default; every engine needs a default or NULL for a
     * populated-table NOT NULL add — that global rule lives in the differ, this hook is for
     * engine-specific limits only.).
     */
    public function addColumnRestriction(ColumnDefinition $col): ?string;
}
