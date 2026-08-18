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

        // Scanned once, read twice: an enum column's member list and the table's own CHECK
        // constraints are the same clauses, told apart by name.
        $checks = self::extractChecks($createSql);

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
                rawEnumCheck: $checks[ColumnDefinition::enumCheckConstraintName($name)] ?? null,
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

        return new LiveTable($tableName, $columns, $primaryKey, $indexes, $foreignKeys, $checks);
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** Best-effort: pull `GENERATED ALWAYS AS (expr)` for a column out of the stored CREATE sql. */
    /**
     * Every named CHECK constraint in the stored CREATE sql, name → body.
     *
     * SQLite has no catalogue of constraints — the DDL text *is* the catalogue — so they are read
     * back out of it. Which is a gift as well as a chore: unlike PostgreSQL and MySQL, SQLite
     * stores the producer's own expression unrewritten, so what comes back is what was written.
     *
     * It still cannot be a plain regex. A member or literal may contain a parenthesis (`'a)b'`),
     * so each body ends where its brackets balance, counted while skipping over single-quoted
     * literals rather than by matching the first `)`. An unbalanced body is left out entirely —
     * unreadable beats guessed.
     *
     * @return array<string, string>
     */
    private static function extractChecks(string $createSql): array
    {
        if (0 === preg_match_all('/CONSTRAINT\s+"?([^"\s]+)"?\s+CHECK\s*\(/i', $createSql, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $checks = [];
        foreach ($matches as $match) {
            /** @psalm-var array{0: array{0: string, 1: int}, 1: array{0: string, 1: int}} $match */
            $body = self::balancedBody($createSql, $match[0][1] + \strlen($match[0][0]));
            if (null !== $body) {
                $checks[$match[1][0]] = $body;
            }
        }

        return $checks;
    }

    /**
     * The text from `$start` up to the bracket that closes the one already open, or null when the
     * brackets never balance. Single-quoted literals are skipped wholesale, `''` inside one being
     * an escaped quote rather than its end.
     */
    private static function balancedBody(string $sql, int $start): ?string
    {
        $i = $start;
        $depth = 1;
        $length = \strlen($sql);

        while ($i < $length) {
            $char = $sql[$i];
            if ("'" === $char) {
                ++$i;
                while ($i < $length) {
                    if ("'" === $sql[$i]) {
                        if ($i + 1 < $length && "'" === $sql[$i + 1]) {
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
                    return substr($sql, $start, $i - $start);
                }
            }
            ++$i;
        }

        return null;
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
