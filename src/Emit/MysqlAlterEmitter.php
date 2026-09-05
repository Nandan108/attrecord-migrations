<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Emit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Schema\CheckDefinition;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;

/**
 * MySQL/MariaDB ALTER wrappers. `MODIFY COLUMN {fragment}` re-specifies the whole column, so a
 * single statement covers every facet; `CHANGE COLUMN old {fragment}` is the data-preserving
 * rename+respec (portable across MySQL 5.x/8 and all MariaDB — unlike `RENAME COLUMN`, which needs
 * 8.0/10.5.2+).
 */
final class MysqlAlterEmitter implements AlterEmitter
{
    public function __construct(private readonly MysqlDialect $dialect)
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
        return ['ALTER TABLE '.$this->q($table).' MODIFY COLUMN '.$this->dialect->buildColumnLine($col)];
    }

    #[\Override]
    public function renameColumn(string $table, string $oldName, ColumnDefinition $col, array $dependents = []): array
    {
        $rename = 'ALTER TABLE '.$this->q($table).' CHANGE COLUMN '.$this->q($oldName).' '.$this->dialect->buildColumnLine($col);
        if ([] === $dependents) {
            return [$rename];
        }

        // MySQL refuses the CHANGE COLUMN outright while a generated column's expression names the
        // old column (error 3108), so each dependent comes out first and goes back afterwards from
        // its *desired* definition, whose expression already names the new column.
        //
        // MariaDB does not need any of this — it accepts the rename and rewrites the stored
        // expression itself — but one emitter serves both families and cannot tell them apart at SQL
        // build time. The differ therefore only sends dependents it is cheap to rebuild: a VIRTUAL
        // generated column stores nothing, so dropping and re-adding it is a catalogue edit on both
        // engines. A STORED one would be a full-table rewrite, and MariaDB would be paying it for
        // nothing, which is why that case never reaches here.
        $statements = [];
        foreach ($dependents as $dependent) {
            $statements[] = 'ALTER TABLE '.$this->q($table).' DROP COLUMN '.$this->q($dependent->name);
        }
        $statements[] = $rename;
        foreach ($dependents as $dependent) {
            $statements[] = 'ALTER TABLE '.$this->q($table).' ADD COLUMN '.$this->dialect->buildColumnLine($dependent);
        }

        return $statements;
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
        return ['DROP INDEX '.$this->q($name).' ON '.$this->q($table)];
    }

    #[\Override]
    public function renameIndex(string $table, string $from, string $to, bool $unique): ?array
    {
        // A unique key is an index here, so one form covers both. MySQL 5.7+, MariaDB 10.5.2+.
        return ['ALTER TABLE '.$this->q($table).' RENAME INDEX '.$this->q($from).' TO '.$this->q($to)];
    }

    #[\Override]
    public function addForeignKey(string $table, ForeignKeyDefinition $fk): array
    {
        return ['ALTER TABLE '.$this->q($table).' ADD '.$this->dialect->buildForeignKeyLine($fk)];
    }

    #[\Override]
    public function dropForeignKey(string $table, string $name): ?array
    {
        return ['ALTER TABLE '.$this->q($table).' DROP FOREIGN KEY '.$this->q($name)];
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
        return null; // no RENAME CONSTRAINT on MySQL/MariaDB — the differ falls back to add + drop
    }

    #[\Override]
    public function addColumnRestriction(ColumnDefinition $col): ?string
    {
        return null;
    }

    private function q(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }
}
