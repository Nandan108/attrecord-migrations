<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Introspect;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\AttrecordMigrations\Live\LiveColumn;
use Nandan108\AttrecordMigrations\Live\LiveForeignKey;
use Nandan108\AttrecordMigrations\Live\LiveIndex;
use Nandan108\AttrecordMigrations\Live\LiveTable;

/**
 * SQLite introspection via `PRAGMA table_xinfo` / `index_list` / `index_info` /
 * `foreign_key_list`.
 *
 * PRAGMA statements cannot take bound parameters, so the table name is interpolated —
 * double-quoted with quote-doubling. Names come from `TableSchema` (developer-authored
 * attributes), not user input, but the quoting is applied unconditionally anyway.
 *
 * Auto-increment: a single-column INTEGER PK is SQLite's rowid alias — the engine assigns ids —
 * so it is reported as auto-increment, whether or not the AUTOINCREMENT keyword (which only adds
 * the monotonic no-reuse guarantee) was used. This matches how the attrecord DDL producer emits
 * AI PKs on SQLite. The stored `sqlite_master.sql` is still read, but only for the two things the
 * pragmas omit entirely: generation expressions and declared FK constraint names (best-effort
 * regex recovery, falling back cleanly when absent).
 */
final class SqliteIntrospector implements SchemaIntrospector
{
    #[\Override]
    public function introspectTable(DbSession $session, string $tableName): ?LiveTable
    {
        $q = self::quote($tableName);

        $columnRows = $session->fetchAll("PRAGMA table_xinfo({$q})");
        if ([] === $columnRows) {
            return null;
        }

        $createSql = (string) ($session->fetchOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $tableName],
        )['sql'] ?? '');

        $primaryKey = [];
        $columns = [];
        /** @var array<int, array{name: string, type: string, notnull: bool, dflt: ?string, hidden: int}> $byPkOrder */
        $byPkOrder = [];
        foreach ($columnRows as $row) {
            $pkOrdinal = (int) ($row['pk'] ?? 0);
            if ($pkOrdinal > 0) {
                $byPkOrder[$pkOrdinal] = ['name' => (string) $row['name'], 'type' => strtolower((string) $row['type']), 'notnull' => 1 === (int) $row['notnull'], 'dflt' => isset($row['dflt_value']) ? (string) $row['dflt_value'] : null, 'hidden' => (int) ($row['hidden'] ?? 0)];
            }
        }
        ksort($byPkOrder);
        foreach ($byPkOrder as $pkCol) {
            $primaryKey[] = $pkCol['name'];
        }
        $isRowidAliasPk = 1 === \count($primaryKey)
            && str_starts_with($byPkOrder[1]['type'] ?? '', 'integer');

        foreach ($columnRows as $row) {
            $name = (string) $row['name'];
            $hidden = (int) ($row['hidden'] ?? 0); // 0 normal, 1 hidden, 2 virtual generated, 3 stored generated
            $isGenerated = 2 === $hidden || 3 === $hidden;
            $isAiPk = $isRowidAliasPk && $name === $primaryKey[0];
            $columns[$name] = new LiveColumn(
                name: $name,
                rawType: strtolower((string) $row['type']),
                nullable: 1 !== (int) $row['notnull'] && !(1 === (int) ($row['pk'] ?? 0)),
                rawDefault: isset($row['dflt_value']) ? (string) $row['dflt_value'] : null,
                autoIncrement: $isAiPk,
                generationExpression: $isGenerated ? self::extractGenerationExpr($createSql, $name) : null,
                rawEnumCheck: self::extractEnumCheck($createSql, $name),
            );
        }

        $indexes = [];
        $indexRows = $session->fetchAll("PRAGMA index_list({$q})");
        foreach ($indexRows as $row) {
            $ixName = (string) $row['name'];
            $origin = (string) ($row['origin'] ?? 'c'); // c = CREATE INDEX, u = UNIQUE constraint, pk = primary key
            if ('pk' === $origin) {
                continue;
            }
            $colRows = $session->fetchAll('PRAGMA index_info('.self::quote($ixName).')');
            usort($colRows, static fn (array $a, array $b): int => (int) $a['seqno'] <=> (int) $b['seqno']);
            $columnsInOrder = array_map(static fn (array $r): string => (string) $r['name'], $colRows);

            // An inline `CONSTRAINT name UNIQUE (…)` materializes as an auto-index named
            // `sqlite_autoindex_<table>_<n>` — the declared name survives only in the stored CREATE
            // sql. Recover it by column-list match so name-based diffing sees the declared name.
            if ('u' === $origin && str_starts_with($ixName, 'sqlite_autoindex_')) {
                $ixName = self::findUniqueConstraintName($createSql, $columnsInOrder) ?? $ixName;
            }

            $indexes[$ixName] = new LiveIndex(
                $ixName,
                $columnsInOrder,
                1 === (int) $row['unique'],
            );
        }

        // foreign_key_list: one row per (constraint id, column seq); constraints are anonymous in
        // SQLite (no names in the pragma), so a synthetic name fk_<table>_<n> keys them stably.
        $fkRows = $session->fetchAll("PRAGMA foreign_key_list({$q})");
        /** @var array<int, array{first: string, local: list<string>, refTable: string, refCols: list<string>, del: string, upd: string}> $fkAcc */
        $fkAcc = [];
        foreach ($fkRows as $row) {
            $id = (int) $row['id'];
            $fkAcc[$id] ??= [
                'first'    => (string) $row['from'], // first local column (pragma rows arrive seq-ordered)
                'local'    => [],
                'refTable' => (string) $row['table'],
                'refCols'  => [],
                'del'      => strtoupper((string) $row['on_delete']),
                'upd'      => strtoupper((string) $row['on_update']),
            ];
            $fkAcc[$id]['local'][] = (string) $row['from'];
            $fkAcc[$id]['refCols'][] = (string) ($row['to'] ?? 'id');
        }
        $foreignKeys = [];
        foreach ($fkAcc as $acc) {
            // Recover the declared constraint name from the stored CREATE TABLE sql where possible;
            // fall back to a synthetic, membership-derived name.
            $name = self::findConstraintName($createSql, $acc['first'])
                ?? 'fk_'.$tableName.'_'.implode('_', $acc['local']);
            $foreignKeys[$name] = new LiveForeignKey(
                name: $name,
                localColumns: $acc['local'],
                referencedTable: $acc['refTable'],
                referencedColumns: $acc['refCols'],
                onDelete: $acc['del'],
                onUpdate: $acc['upd'],
            );
        }

        return new LiveTable($tableName, $columns, $primaryKey, $indexes, $foreignKeys);
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** Best-effort: pull `GENERATED ALWAYS AS (expr)` for a column out of the stored CREATE sql. */
    /**
     * Recover the body of the producer's `chk_<column>_enum` CHECK constraint from the stored
     * CREATE sql — how enum members stay visible on SQLite, which has no native ENUM type.
     *
     * SQLite stores the DDL text verbatim, so unlike PostgreSQL this is the producer's own
     * expression, unrewritten. It still cannot be a plain regex: a member may contain a
     * parenthesis (`'a)b'`), so the closing paren is found by balancing while skipping over
     * single-quoted literals rather than by matching the first `)`.
     */
    private static function extractEnumCheck(string $createSql, string $column): ?string
    {
        $marker = 'CONSTRAINT "'.ColumnDefinition::enumCheckConstraintName($column).'" CHECK (';
        $at = stripos($createSql, $marker);
        if (false === $at) {
            return null;
        }

        $i = $at + \strlen($marker);
        $depth = 1;
        $length = \strlen($createSql);
        $start = $i;

        while ($i < $length) {
            $char = $createSql[$i];
            if ("'" === $char) {
                // Skip the literal wholesale; `''` inside it is an escaped quote, not a close.
                ++$i;
                while ($i < $length) {
                    if ("'" === $createSql[$i]) {
                        if ($i + 1 < $length && "'" === $createSql[$i + 1]) {
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    ++$i;
                }
            } elseif ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                if (0 === --$depth) {
                    return substr($createSql, $start, $i - $start);
                }
            }
            ++$i;
        }

        return null; // unbalanced — treat as unreadable rather than guess
    }

    private static function extractGenerationExpr(string $createSql, string $column): ?string
    {
        $pattern = '/"?'.preg_quote($column, '/').'"?\s+\S+\s+GENERATED\s+ALWAYS\s+AS\s*\((.*?)\)\s*(?:STORED|VIRTUAL)/is';
        if (1 === preg_match($pattern, $createSql, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Best-effort: recover a declared `CONSTRAINT "name" UNIQUE ("a", "b")` name from the stored
     * CREATE sql by matching the auto-index's column list against each UNIQUE clause's columns.
     */
    private static function findUniqueConstraintName(string $createSql, array $columns): ?string
    {
        if (0 === preg_match_all('/CONSTRAINT\s+"?([^"\s]+)"?\s+UNIQUE\s*\(([^)]*)\)/i', $createSql, $matches, \PREG_SET_ORDER)) {
            return null;
        }
        foreach ($matches as $m) {
            $declaredCols = array_map(
                static fn (string $part): string => trim(trim($part), '"'),
                explode(',', $m[2]),
            );
            if ($declaredCols === $columns) {
                return $m[1];
            }
        }

        return null;
    }

    /** Best-effort: recover `CONSTRAINT "name" FOREIGN KEY ("col"…` from the stored CREATE sql. */
    private static function findConstraintName(string $createSql, string $firstLocalColumn): ?string
    {
        $pattern = '/CONSTRAINT\s+"?([^"\s]+)"?\s+FOREIGN\s+KEY\s*\(\s*"?'.preg_quote($firstLocalColumn, '/').'"?/i';
        if (1 === preg_match($pattern, $createSql, $m)) {
            return $m[1];
        }

        return null;
    }
}
