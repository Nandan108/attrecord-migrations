<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\AttrecordMigrations\Live\LiveColumn;

/**
 * MySQL/MariaDB canonicalization. Desired side mirrors `MysqlDialect::renderColumnType()`
 * (`Bool` → `tinyint(1)` → family `bool`; enum/set carry their member lists). Live side owns the
 * engine's reporting quirks: integer display widths (`int(11)`), `tinyint(1)` ≡ bool, MariaDB's
 * quoted literal defaults and the string `'NULL'` for no-default, `current_timestamp()` spellings.
 */
final class MysqlColumnNormalizer extends AbstractColumnNormalizer
{
    #[\Override]
    public function normalizeDesired(ColumnDefinition $col): NormalizedColumn
    {
        $type = $col->type;
        $precision = ($col->precision ?? 0) ?: null;

        [$family, $unsigned, $length, $p, $s, $members] = match (true) {
            ColumnType::Bool === $type      => ['bool', false, null, null, null, null],
            ColumnType::VarChar === $type   => ['varchar', false, $col->length, null, null, null],
            ColumnType::Char === $type      => ['char', false, $col->length, null, null, null],
            ColumnType::Binary === $type    => ['binary', false, $col->length, null, null, null],
            ColumnType::VarBinary === $type => ['varbinary', false, $col->length, null, null, null],
            ColumnType::Bit === $type       => ['bit', false, $col->length, null, null, null],
            ColumnType::Decimal === $type   => ['decimal', false, null, $col->precision, $col->scale ?? 0, null],
            ColumnType::DateTime === $type  => ['datetime', false, null, $precision, null, null],
            ColumnType::Timestamp === $type => ['timestamp', false, null, $precision, null, null],
            ColumnType::Enum === $type      => ['enum', false, null, null, null, $col->enumValues ?? []],
            ColumnType::Set === $type       => ['set', false, null, null, null, $col->enumValues ?? []],
            default                         => self::familyFromEnumValue($type->value) + [5 => null],
        };

        // MariaDB stores JSON as LONGTEXT (COLUMN_TYPE reports `longtext`); fold the family so both
        // engines agree. Cost: json<->longtext drift is undetectable on MySQL-family in v0.1.
        if ('json' === $family) {
            $family = 'longtext';
        }

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: $unsigned,
            length: $length,
            precision: $p,
            scale: $s,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, self::desiredDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generatedAs),
            members: $members,
        ));
    }

    #[\Override]
    public function normalizeLive(LiveColumn $col): NormalizedColumn
    {
        $raw = trim($col->rawType);

        // tinyint(1) — with no unsigned/width variance — is the engine's rendering of Bool.
        if ('tinyint(1)' === $raw) {
            $parsed = ['bool', false, null, null, null, null];
        } elseif (1 === preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?( unsigned)?( zerofill)?$/', $raw, $m)) {
            $parsed = [$m[1], isset($m[2]) && '' !== $m[2], null, null, null, null];
        } elseif (1 === preg_match('/^(varchar|char|binary|varbinary|bit)\((\d+)\)$/', $raw, $m)) {
            $parsed = [$m[1], false, (int) $m[2], null, null, null];
        } elseif (1 === preg_match('/^(decimal|numeric)\((\d+),\s*(\d+)\)$/', $raw, $m)) {
            $parsed = ['decimal', false, null, (int) $m[2], (int) $m[3], null];
        } elseif (1 === preg_match('/^(datetime|timestamp|time)(?:\((\d+)\))?$/', $raw, $m)) {
            $precision = isset($m[2]) ? (int) $m[2] : 0;
            $parsed = [$m[1], false, null, 0 !== $precision ? $precision : null, null, null];
        } elseif (1 === preg_match('/^(enum|set)\(/', $raw, $m)) {
            $members = self::parseMembers($raw);
            if (null === $members) {
                return NormalizedColumn::unsure("unparseable {$m[1]} member list: {$raw}");
            }
            $parsed = [$m[1], false, null, null, null, $members];
        } elseif (1 === preg_match('/^(json|date|year|float|double|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob)$/', $raw, $m)) {
            $parsed = [$m[1], false, null, null, null, null];
        } else {
            return NormalizedColumn::unsure("unrecognized MySQL column type: {$raw}");
        }

        [$family, $unsigned, $length, $p, $s, $members] = $parsed;
        if ('json' === $family) {
            $family = 'longtext'; // see normalizeDesired — MariaDB JSON alias fold
        }

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: $unsigned,
            length: $length,
            precision: $p,
            scale: $s,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, $this->liveDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generationExpression),
            members: $members,
        ));
    }

    private function liveDefault(LiveColumn $col): ?string
    {
        if ($col->autoIncrement || null !== $col->generationExpression) {
            return null;
        }
        $raw = $col->rawDefault;
        if (null === $raw || 'null' === strtolower($raw)) {
            return null; // MariaDB reports the *string* 'NULL' for a nullable no-default column.
        }
        if (1 === preg_match('/^current_timestamp(\(\d*\))?$/i', $raw)) {
            return self::canonExpr($raw);
        }

        return self::stripOuterQuotes($raw); // MariaDB quotes literal defaults; MySQL 8 does not.
    }

    /**
     * Fallback for the plain families whose `ColumnType` value string *is* the MySQL type —
     * `smallint unsigned`, `text`, `json`, `date`, … (mirrors the dialect's default branch).
     *
     * @return array{0: string, 1: bool, 2: null, 3: null, 4: null}
     */
    private static function familyFromEnumValue(string $value): array
    {
        $unsigned = str_ends_with($value, ' unsigned');

        return [$unsigned ? substr($value, 0, -9) : $value, $unsigned, null, null, null];
    }
}
