<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Emit;

use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Schema\CheckDefinition;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;

/**
 * SQLite ALTER wrappers — deliberately the smallest set. SQLite supports ADD COLUMN (3.x),
 * RENAME COLUMN (3.25+), DROP COLUMN (3.35+) and index DDL; it has **no** in-place column
 * modification and no FK add/drop — those need the table-rebuild dance, which is phase 2 of the
 * design (the design contract §4.4) and classifies **Manual** in v0.1: this emitter returns null
 * and the differ surfaces the change without SQL.
 *
 * @see the design contract — the design contract this implements.
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class SqliteAlterEmitter implements AlterEmitter
{
    public function __construct(private readonly SqliteDialect $dialect)
    {
    }

    #[\Override]
    public function addColumn(string $table, ColumnDefinition $col): array
    {
        return ['ALTER TABLE '.$this->q($table).' ADD COLUMN '.$this->dialect->buildColumnLine($col)];
    }

    #[\Override]
    public function modifyColumn(string $table, ColumnDefinition $col, ColumnTuple $desired, array $facets): ?array
    {
        // No in-place modification — §4.4 rebuild boundary, Manual in v0.1. Enum member drift
        // lands here too: SQLite has no DROP CONSTRAINT, so widening the CHECK that carries the
        // members needs the 12-step table rebuild. It is now *detected* rather than silently
        // ignored, which is the point — a Manual change tells the operator what to do.
        return null;
    }

    #[\Override]
    public function renameColumn(string $table, string $oldName, ColumnDefinition $col): array
    {
        return ['ALTER TABLE '.$this->q($table).' RENAME COLUMN '.$this->q($oldName).' TO '.$this->q($col->name)];
    }

    #[\Override]
    public function dropColumn(string $table, string $column): array
    {
        return ['ALTER TABLE '.$this->q($table).' DROP COLUMN '.$this->q($column)];
    }

    #[\Override]
    public function createIndex(string $table, string $name, array $columns, bool $unique): array
    {
        $cols = implode(', ', array_map($this->q(...), $columns));

        return ['CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.$this->q($name).' ON '.$this->q($table)." ({$cols})"];
    }

    #[\Override]
    public function dropIndex(string $table, string $name): array
    {
        return ['DROP INDEX IF EXISTS '.$this->q($name)];
    }

    #[\Override]
    public function addForeignKey(string $table, ForeignKeyDefinition $fk): array
    {
        return []; // unreachable in practice: the differ routes FK changes to Manual on SQLite
    }

    #[\Override]
    public function dropForeignKey(string $table, string $name): ?array
    {
        return null; // no in-place FK drop — table rebuild, Manual in v0.1
    }

    #[\Override]
    public function addCheck(string $table, CheckDefinition $check): ?array
    {
        return null; // SQLite has no ADD CONSTRAINT — table rebuild, Manual
    }

    #[\Override]
    public function dropCheck(string $table, string $name): ?array
    {
        return null; // nor DROP CONSTRAINT — same rebuild boundary
    }

    #[\Override]
    public function renameForeignKey(string $table, string $from, string $to): ?array
    {
        return null; // constraints are not separately addressable — table rebuild, Manual
    }

    #[\Override]
    public function addColumnRestriction(ColumnDefinition $col): ?string
    {
        // SQLite's ADD COLUMN only accepts constant defaults — CURRENT_TIMESTAMP & co. are
        // rejected by the engine (they are fine in CREATE TABLE, just not in ADD COLUMN).
        if (null !== $col->defaultExpr && 1 !== preg_match('/^(NULL|TRUE|FALSE|-?\d+(\.\d+)?|\'.*\')$/i', trim($col->defaultExpr))) {
            return "SQLite ADD COLUMN cannot use non-constant default {$col->defaultExpr}";
        }

        return null;
    }

    private function q(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }
}
