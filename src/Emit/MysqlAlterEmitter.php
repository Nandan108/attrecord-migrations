<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Emit;

use Nandan108\Attrecord\Dialect\MysqlDialect;
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
    public function renameColumn(string $table, string $oldName, ColumnDefinition $col): array
    {
        return ['ALTER TABLE '.$this->q($table).' CHANGE COLUMN '.$this->q($oldName).' '.$this->dialect->buildColumnLine($col)];
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
