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

        return new LiveTable($tableName, $columns, $primaryKey, $indexes, $foreignKeys, $this->checks($session, $tableName));
    }

    /**
     * CHECK constraints on this table, name → body.
     *
     * Two shapes, because the two engines expose the same view differently. **MariaDB** scopes
     * CHECK names per table and its `CHECK_CONSTRAINTS` carries `TABLE_NAME`, so one filtered read
     * is exact. **MySQL** scopes them per *schema* and its `CHECK_CONSTRAINTS` therefore has no
     * table column at all — the table has to come from `TABLE_CONSTRAINTS`, joined on schema plus
     * name, which is unambiguous there for that same reason.
     *
     * Trying MariaDB's shape first and falling back is deliberate: the fallback query is valid on
     * *both* engines, so probing the other way round would silently take the join path on MariaDB
     * and cross-join two same-named constraints from different tables.
     *
     * Both views are absent on engines predating CHECK support (MySQL below 8.0.16, MariaDB below
     * 10.2.22), where the second query fails too and the answer is "no CHECK constraints" — honest,
     * and it keeps the differ from proposing constraints such an engine would ignore anyway.
     *
     * @return array<array-key, string>
     */
    private function checks(DbSession $session, string $tableName): array
    {
        $rows = $this->tryFetch(
            $session,
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE
               FROM information_schema.CHECK_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName],
        ) ?? $this->tryFetch(
            $session,
            "SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
               FROM information_schema.TABLE_CONSTRAINTS tc
               JOIN information_schema.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
              WHERE tc.TABLE_SCHEMA = DATABASE()
                AND tc.TABLE_NAME = ?
                AND tc.CONSTRAINT_TYPE = 'CHECK'",
            [$tableName],
        ) ?? [];

        $checks = [];
        foreach ($rows as $row) {
            $checks[(string) $row['CONSTRAINT_NAME']] = (string) ($row['CHECK_CLAUSE'] ?? '');
        }

        return $checks;
    }

    /**
     * Run a catalogue query, or null when this engine does not have that view/column. Catalogue
     * probing is the one place where an error is an *answer* rather than a failure.
     *
     * @param list<scalar> $params
     *
     * @return list<array<string, mixed>>|null
     */
    private function tryFetch(DbSession $session, string $sql, array $params): ?array
    {
        try {
            return $session->fetchAll($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
