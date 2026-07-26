<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Introspect;

use Nandan108\Attrecord\DbSession;
use Nandan108\AttrecordMigrations\Live\LiveColumn;
use Nandan108\AttrecordMigrations\Live\LiveForeignKey;
use Nandan108\AttrecordMigrations\Live\LiveIndex;
use Nandan108\AttrecordMigrations\Live\LiveTable;

/**
 * PostgreSQL introspection, scoped to `current_schema()`. `information_schema` supplies columns
 * and constraints; `pg_catalog` supplements where it is lossy — secondary indexes (which are not
 * constraints and therefore invisible to `information_schema.table_constraints`) come from
 * `pg_index`.
 *
 * The raw type is rebuilt as `udt_name` + rendered length/precision (e.g. `varchar(64)`,
 * `numeric(10,2)`, `timestamp(6)`), because `data_type` ("character varying") drops the length and
 * `udt_name` alone drops it too. A `nextval('…_seq'…)` default marks the column auto-increment
 * (that is how SERIAL/BIGSERIAL round-trip) and is *not* reported as a default.
 */
final class PgsqlIntrospector implements SchemaIntrospector
{
    #[\Override]
    public function introspectTable(DbSession $session, string $tableName): ?LiveTable
    {
        $columnRows = $session->fetchAll(
            'SELECT column_name, udt_name, is_nullable, column_default,
                    character_maximum_length, numeric_precision, numeric_scale, datetime_precision,
                    is_identity, is_generated, generation_expression
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
             ORDER BY ordinal_position',
            [$tableName],
        );
        if ([] === $columnRows) {
            return null;
        }

        $columns = [];
        foreach ($columnRows as $row) {
            $name = (string) $row['column_name'];
            $udt = strtolower((string) $row['udt_name']);
            $rawDefault = isset($row['column_default']) ? (string) $row['column_default'] : null;

            // SERIAL/BIGSERIAL introspect as int4/int8 + a nextval() default; identity columns
            // (GENERATED … AS IDENTITY) report via is_identity. Both mean "engine assigns the id".
            $isSequenceDefault = null !== $rawDefault && str_starts_with(strtolower($rawDefault), 'nextval(');
            $autoIncrement = $isSequenceDefault || 'YES' === strtoupper((string) ($row['is_identity'] ?? 'NO'));

            $generation = (string) ($row['generation_expression'] ?? '');

            $columns[$name] = new LiveColumn(
                name: $name,
                rawType: self::renderType(
                    $udt,
                    isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
                    isset($row['numeric_precision']) ? (int) $row['numeric_precision'] : null,
                    isset($row['numeric_scale']) ? (int) $row['numeric_scale'] : null,
                    isset($row['datetime_precision']) ? (int) $row['datetime_precision'] : null,
                ),
                nullable: 'YES' === strtoupper((string) $row['is_nullable']),
                rawDefault: $isSequenceDefault ? null : $rawDefault,
                autoIncrement: $autoIncrement,
                generationExpression: '' !== $generation ? $generation : null,
            );
        }

        // PK + unique constraints from information_schema (both are table constraints in PG).
        $constraintRows = $session->fetchAll(
            "SELECT tc.constraint_name, tc.constraint_type, kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.constraint_schema = tc.constraint_schema
             WHERE tc.table_schema = current_schema() AND tc.table_name = ?
               AND tc.constraint_type IN ('PRIMARY KEY', 'UNIQUE')
             ORDER BY tc.constraint_name, kcu.ordinal_position",
            [$tableName],
        );
        $primaryKey = [];
        /** @var array<string, list<string>> $uniqueAcc */
        $uniqueAcc = [];
        foreach ($constraintRows as $row) {
            if ('PRIMARY KEY' === (string) $row['constraint_type']) {
                $primaryKey[] = (string) $row['column_name'];
            } else {
                $uniqueAcc[(string) $row['constraint_name']][] = (string) $row['column_name'];
            }
        }
        $indexes = [];
        foreach ($uniqueAcc as $ixName => $cols) {
            $indexes[$ixName] = new LiveIndex($ixName, $cols, true);
        }

        // Secondary (non-constraint, non-PK) indexes from pg_index.
        $indexRows = $session->fetchAll(
            'SELECT i.relname AS index_name, a.attname AS column_name,
                    ix.indisunique::int AS is_unique,
                    array_position(ix.indkey::int2[], a.attnum) AS pos
             FROM pg_class t
             JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = current_schema()
             JOIN pg_index ix ON ix.indrelid = t.oid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey)
             WHERE t.relname = ? AND NOT ix.indisprimary
             ORDER BY i.relname, pos',
            [$tableName],
        );
        /** @var array<string, array{columns: list<string>, unique: bool}> $indexAcc */
        $indexAcc = [];
        foreach ($indexRows as $row) {
            $ixName = (string) $row['index_name'];
            if (isset($indexes[$ixName])) {
                continue; // already captured as a UNIQUE constraint (constraints are backed by an index of the same name)
            }
            $indexAcc[$ixName] ??= ['columns' => [], 'unique' => 1 === (int) $row['is_unique']];
            $indexAcc[$ixName]['columns'][] = (string) $row['column_name'];
        }
        foreach ($indexAcc as $ixName => $acc) {
            $indexes[$ixName] = new LiveIndex($ixName, $acc['columns'], $acc['unique']);
        }

        $fkRows = $session->fetchAll(
            "SELECT tc.constraint_name, kcu.column_name,
                    ccu.table_name AS ref_table, ccu.column_name AS ref_column,
                    rc.delete_rule, rc.update_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.constraint_schema = tc.constraint_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.constraint_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema
             WHERE tc.table_schema = current_schema() AND tc.table_name = ?
               AND tc.constraint_type = 'FOREIGN KEY'
             ORDER BY tc.constraint_name, kcu.ordinal_position",
            [$tableName],
        );
        /** @var array<string, array{local: list<string>, refTable: string, refCols: list<string>, del: string, upd: string}> $fkAcc */
        $fkAcc = [];
        foreach ($fkRows as $row) {
            $fkName = (string) $row['constraint_name'];
            $fkAcc[$fkName] ??= [
                'local'    => [],
                'refTable' => (string) $row['ref_table'],
                'refCols'  => [],
                'del'      => strtoupper((string) $row['delete_rule']),
                'upd'      => strtoupper((string) $row['update_rule']),
            ];
            $fkAcc[$fkName]['local'][] = (string) $row['column_name'];
            $fkAcc[$fkName]['refCols'][] = (string) $row['ref_column'];
        }
        $foreignKeys = [];
        foreach ($fkAcc as $fkName => $acc) {
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

    /** Rebuild a display type from udt + dimensions: `varchar(64)`, `numeric(10,2)`, `timestamp(6)`, `int8`. */
    private static function renderType(string $udt, ?int $charLen, ?int $numPrecision, ?int $numScale, ?int $dtPrecision): string
    {
        if (null !== $charLen && in_array($udt, ['varchar', 'bpchar'], true)) {
            return "{$udt}({$charLen})";
        }
        if ('numeric' === $udt && null !== $numPrecision) {
            return "numeric({$numPrecision},".(int) $numScale.')';
        }
        if (null !== $dtPrecision && in_array($udt, ['timestamp', 'timestamptz', 'time', 'timetz'], true)) {
            return "{$udt}({$dtPrecision})";
        }

        return $udt;
    }
}
