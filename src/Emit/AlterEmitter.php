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

    /**
     * Rename a column, re-specifying any generated column whose expression depends on it.
     *
     * **MySQL refuses to rename a column another column's `GENERATED ALWAYS` expression mentions**
     * — `ERROR 3108, Column 'x' has a generated column dependency` — and the `ALTER` does not run at
     * all. MariaDB accepts the same statement and rewrites the stored expression to the new name by
     * itself, so this is invisible on a MariaDB dev machine and fatal on a MySQL install.
     *
     * `$dependents` carries the **desired** definitions of the generated columns that name the old
     * column, so an emitter that needs to can re-point each at the new name in the same statement as
     * the rename. Engines that rewrite references themselves (PostgreSQL, SQLite) ignore the
     * parameter.
     *
     * **Re-specifying is not dropping and re-adding**, and the difference is not a matter of taste:
     * dropping a generated column takes its indexes with it — an index over it alone disappears
     * outright, a composite index silently loses that column and keeps the rest — and adding the
     * column back restores none of them. There is no error and no wrong answer, only queries that
     * quietly stop using an index. `MODIFY COLUMN` never removes the column, so the indexes are
     * never in question.
     *
     * @param list<ColumnDefinition> $dependents generated columns referencing `$oldName`, desired shape
     *
     * @return list<string>
     */
    public function renameColumn(string $table, string $oldName, ColumnDefinition $col, array $dependents = []): array;

    /**
     * Whether {@see renameColumn()} re-specifies the dependents it is given, rather than ignoring
     * them.
     *
     * True only on the MySQL family, which needs it (error 3108 above). It is what lets the differ
     * price a rename per engine instead of globally: re-specifying a **`STORED`** dependent
     * recomputes it for every row, which is the table rewrite `Assisted` exists to hold back — while
     * on PostgreSQL and SQLite the very same rename is a catalogue update and stays `Safe`. A
     * `VIRTUAL` dependent stores nothing and costs nothing to re-point, so it never escalates.
     */
    public function renameRespecifiesDependents(): bool;

    /** @return list<string> */
    public function dropColumn(string $table, string $column): array;

    /** @param list<string> $columns
     * @return list<string> */
    public function createIndex(string $table, string $name, array $columns, bool $unique): array;

    /** @return list<string> */
    public function dropIndex(string $table, string $name): array;

    /**
     * Rename an index in place, or null when the engine has no such operation (SQLite), leaving the
     * differ to create-then-drop instead.
     *
     * Unlike {@see renameForeignKey()} this is available almost everywhere — `RENAME INDEX` on
     * MySQL 5.7+/MariaDB 10.5.2+, `ALTER INDEX … RENAME TO` on PostgreSQL — and everywhere it
     * exists it is catalogue-only. That matters more here than it does for a constraint: the
     * fallback is a full index **build**, so an engine that can rename turns a rewrite of the whole
     * table's worth of entries into a name change.
     *
     * `$unique` is passed because the engines disagree about what a unique key *is*: PostgreSQL
     * models it as a table constraint (renamed through `ALTER TABLE`), MySQL as an ordinary index.
     *
     * @return list<string>|null
     */
    public function renameIndex(string $table, string $from, string $to, bool $unique): ?array;

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
