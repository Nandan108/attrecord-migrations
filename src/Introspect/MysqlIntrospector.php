<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Introspect;

use Nandan108\Attrecord\DbSession;
use Nandan108\AttrecordMigrations\Live\LiveColumn;
use Nandan108\AttrecordMigrations\Live\LiveForeignKey;
use Nandan108\AttrecordMigrations\Live\LiveIndex;
use Nandan108\AttrecordMigrations\Live\LiveTable;

/**
 * MySQL/MariaDB introspection via `information_schema`, scoped to the connection's current
 * database (`DATABASE()`).
 *
 * `COLUMN_TYPE` (not `DATA_TYPE`) is captured as the raw type: it carries the full rendered form —
 * display width, `unsigned`, `enum('a','b')` member lists — which is what the normalizer needs.
 * `EXTRA` supplies auto-increment, generated-column mode, and the MySQL-family `on update` clause.
 */
final class MysqlIntrospector implements SchemaIntrospector
{
    #[\Override]
    public function introspectTable(DbSession $session, string $tableName): ?LiveTable
    {
        $columnRows = $session->fetchAll(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$tableName],
        );
        if ([] === $columnRows) {
            return null;
        }

        $columns = [];
        foreach ($columnRows as $row) {
            $name = (string) $row['COLUMN_NAME'];
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            $generation = (string) ($row['GENERATION_EXPRESSION'] ?? '');
            $onUpdate = null;
            if (1 === preg_match('/on update (.+)$/i', $extra, $m)) {
                $onUpdate = trim($m[1]);
            }
            $columns[$name] = new LiveColumn(
                name: $name,
                rawType: strtolower((string) $row['COLUMN_TYPE']),
                nullable: 'YES' === strtoupper((string) $row['IS_NULLABLE']),
                rawDefault: isset($row['COLUMN_DEFAULT']) ? (string) $row['COLUMN_DEFAULT'] : null,
                autoIncrement: str_contains($extra, 'auto_increment'),
                generationExpression: '' !== $generation ? $generation : null,
                rawOnUpdate: $onUpdate,
            );
        }

        // Indexes + PK: STATISTICS has one row per (index, position). INDEX_NAME 'PRIMARY' is the PK.
        $indexRows = $session->fetchAll(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$tableName],
        );
        $primaryKey = [];
        /** @var array<array-key, array{columns: list<string>, unique: bool}> $indexAcc */
        $indexAcc = [];
        foreach ($indexRows as $row) {
            $ixName = (string) $row['INDEX_NAME'];
            $colName = (string) $row['COLUMN_NAME'];
            if ('PRIMARY' === $ixName) {
                $primaryKey[] = $colName;
                continue;
            }
            $indexAcc[$ixName] ??= ['columns' => [], 'unique' => 0 === (int) $row['NON_UNIQUE']];
            $indexAcc[$ixName]['columns'][] = $colName;
        }
        $indexes = [];
        foreach ($indexAcc as $ixName => $acc) {
            // Re-cast: PHP silently converts a numeric-string array key to int on assignment, so
            // the (string) applied when accumulating is gone by the time we read it back. Engines
            // do produce such names — see the foreign-key loop below.
            $ixName = (string) $ixName;
            $indexes[$ixName] = new LiveIndex($ixName, $acc['columns'], $acc['unique']);
        }

        $fkRows = $session->fetchAll(
            'SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$tableName],
        );
        /** @var array<array-key, array{local: list<string>, refTable: string, refCols: list<string>, del: string, upd: string}> $fkAcc */
        $fkAcc = [];
        foreach ($fkRows as $row) {
            $fkName = (string) $row['CONSTRAINT_NAME'];
            $fkAcc[$fkName] ??= [
                'local'    => [],
                'refTable' => (string) $row['REFERENCED_TABLE_NAME'],
                'refCols'  => [],
                'del'      => strtoupper((string) $row['DELETE_RULE']),
                'upd'      => strtoupper((string) $row['UPDATE_RULE']),
            ];
            $fkAcc[$fkName]['local'][] = (string) $row['COLUMN_NAME'];
            $fkAcc[$fkName]['refCols'][] = (string) $row['REFERENCED_COLUMN_NAME'];
        }
        $foreignKeys = [];
        foreach ($fkAcc as $fkName => $acc) {
            // MariaDB names an unnamed FK constraint with a bare ordinal — `1`, `2`, … — and PHP
            // turns a numeric-string array key into an int, so `$fkName` arrives here as an int
            // however carefully it was cast on the way in. Left uncast it is a TypeError, which is
            // to say: any table with an unnamed foreign key was uninspectable.
            $fkName = (string) $fkName;
            $foreignKeys[$fkName] = new LiveForeignKey(
                name: $fkName,
                localColumns: $acc['local'],
                referencedTable: $acc['refTable'],
                referencedColumns: $acc['refCols'],
                onDelete: $acc['del'],
                onUpdate: $acc['upd'],
            );
        }

        return new LiveTable($tableName, $columns, $primaryKey, $indexes, $foreignKeys);
    }
}
