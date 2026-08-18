<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Emit;

use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Schema\CheckDefinition;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;

/**
 * PostgreSQL ALTER wrappers. PG has no `MODIFY COLUMN`; a modification decomposes into granular
 * `ALTER COLUMN` sub-clauses — `TYPE` (via the dialect's bare-type seam), `SET/DROP NOT NULL`,
 * `SET/DROP DEFAULT` — combined into one `ALTER TABLE` statement. Only the drifted facets are
 * touched. A `TYPE` change without an implicit cast fails loudly (no `USING` is ever guessed).
 */
final class PgsqlAlterEmitter implements AlterEmitter
{
    public function __construct(private readonly PgsqlDialect $dialect)
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
        $qc = $this->q($col->name);
        $clauses = [];

        if ([] !== array_intersect($facets, ['type', 'unsigned', 'length', 'precision', 'scale', 'members'])) {
            $clauses[] = "ALTER COLUMN {$qc} TYPE ".$this->dialect->renderColumnType($col);
        }
        if (\in_array('members', $facets, true)) {
            // An enum's members live in a CHECK constraint on PG, so retyping the column (TEXT ->
            // TEXT) changes nothing on its own — the constraint has to be swapped. PG has no
            // "alter constraint body", so it is a drop and an add in one statement.
            //
            // Two drops: the producer's own name, and the name PG auto-assigns to an anonymous
            // column CHECK ({table}_{column}_check), which is what tables created before the
            // constraint was named still carry. IF EXISTS makes the irrelevant one a no-op, so a
            // table from either era converges without the emitter needing to know which it is.
            $constraint = ColumnDefinition::enumCheckConstraintName($col->name);
            $clauses[] = 'DROP CONSTRAINT IF EXISTS '.$this->q($constraint);
            $clauses[] = 'DROP CONSTRAINT IF EXISTS '.$this->q($table.'_'.$col->name.'_check');
            $clauses[] = 'ADD CONSTRAINT '.$this->q($constraint)
                ." CHECK ({$qc} IN (".implode(', ', array_map(
                    fn (string $v): string => $this->dialect->toLiteral($v, $col),
                    $col->enumValues ?? [],
                )).'))';
        }
        if (\in_array('nullable', $facets, true)) {
            $clauses[] = "ALTER COLUMN {$qc} ".($col->nullable ? 'DROP NOT NULL' : 'SET NOT NULL');
        }
        if (\in_array('default', $facets, true)) {
            $clauses[] = "ALTER COLUMN {$qc} ".(null === $desired->default
                ? 'DROP DEFAULT'
                : 'SET DEFAULT '.$this->renderDefault($col));
        }
        if ([] === $clauses) {
            return [];
        }

        return ['ALTER TABLE '.$this->q($table).' '.implode(', ', $clauses)];
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
        if ($unique) {
            // Match the producer: uniques are table CONSTRAINTs on PG (introspected via
            // table_constraints), not bare unique indexes.
            return ['ALTER TABLE '.$this->q($table).' ADD CONSTRAINT '.$this->q($name)." UNIQUE ({$cols})"];
        }

        return ['CREATE INDEX '.$this->q($name).' ON '.$this->q($table)." ({$cols})"];
    }

    #[\Override]
    public function dropIndex(string $table, string $name): array
    {
        // A unique KEY is a constraint on PG, a secondary index is not; DROP CONSTRAINT IF EXISTS +
        // DROP INDEX IF EXISTS covers both without the caller having to know which it was. Exactly
        // one of the two acts; the other is a no-op.
        return [
            'ALTER TABLE '.$this->q($table).' DROP CONSTRAINT IF EXISTS '.$this->q($name),
            'DROP INDEX IF EXISTS '.$this->q($name),
        ];
    }

    #[\Override]
    public function addForeignKey(string $table, ForeignKeyDefinition $fk): array
    {
        return ['ALTER TABLE '.$this->q($table).' ADD '.$this->dialect->buildForeignKeyLine($fk)];
    }

    #[\Override]
    public function dropForeignKey(string $table, string $name): ?array
    {
        return ['ALTER TABLE '.$this->q($table).' DROP CONSTRAINT '.$this->q($name)];
    }

    #[\Override]
    public function addCheck(string $table, CheckDefinition $check): ?array
    {
        return ['ALTER TABLE '.$this->q($table).' ADD '.$this->dialect->buildCheckLine($check)];
    }

    #[\Override]
    public function dropCheck(string $table, string $name): ?array
    {
        // DROP CONSTRAINT rather than MySQL's DROP CHECK: both engines accept it (verified on
        // MySQL 8.0 and MariaDB 11.8), where DROP CHECK is MySQL-only.
        return ['ALTER TABLE '.$this->q($table).' DROP CONSTRAINT '.$this->q($name)];
    }

    #[\Override]
    public function renameForeignKey(string $table, string $from, string $to): ?array
    {
        // Catalogue-only: no row validation, no rewrite. The reason a rename is Safe here and
        // Destructive on MySQL/MariaDB, which have to add and drop instead.
        return ['ALTER TABLE '.$this->q($table).' RENAME CONSTRAINT '.$this->q($from).' TO '.$this->q($to)];
    }

    #[\Override]
    public function addColumnRestriction(ColumnDefinition $col): ?string
    {
        return null;
    }

    private function renderDefault(ColumnDefinition $col): string
    {
        if (null !== $col->defaultExpr) {
            return $col->defaultExpr;
        }

        return $this->dialect->toLiteral($col->default, $col);
    }

    private function q(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }
}
