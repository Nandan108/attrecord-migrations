<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\AttrecordMigrations\Live\LiveColumn;

/**
 * PostgreSQL canonicalization. Both sides collapse what PG cannot represent: `unsigned` is always
 * false, binary lengths are null (BYTEA is unsized), and the tiny/medium integer families fold to
 * PG's smallint/integer/bigint — mirroring `PgsqlDialect::renderColumnType()`. Enum members are
 * invisible on PG (the producer stores them in a CHECK constraint, which introspection does not
 * model in v0.1), so `members` is null on both sides — member drift is undetected here and caught
 * on MySQL-family databases.
 *
 * PG quirk owned here: a bare TIMESTAMP *is* timestamp(6), so precision null/0 canonicalizes to 6.
 */
final class PgsqlColumnNormalizer extends AbstractColumnNormalizer
{
    #[\Override]
    public function normalizeDesired(ColumnDefinition $col): NormalizedColumn
    {
        $type = $col->type;

        if (ColumnType::Bit === $type) {
            // PG reports BIT without its length through this introspection path; refuse to guess.
            return NormalizedColumn::unsure('BIT round-trip is not modeled on PostgreSQL');
        }

        [$family, $length, $p, $s] = match (true) {
            ColumnType::Bool === $type => ['bool', null, null, null],
            ColumnType::TinyInt === $type, ColumnType::TinyIntUnsigned === $type,
            ColumnType::SmallInt === $type, ColumnType::SmallIntUnsigned === $type,
            ColumnType::Year === $type => ['smallint', null, null, null],
            ColumnType::MediumInt === $type, ColumnType::MediumIntUnsigned === $type,
            ColumnType::Int === $type, ColumnType::IntUnsigned === $type       => ['integer', null, null, null],
            ColumnType::BigInt === $type, ColumnType::BigIntUnsigned === $type => ['bigint', null, null, null],
            ColumnType::Float === $type                                        => ['real', null, null, null],
            ColumnType::Double === $type                                       => ['double', null, null, null],
            ColumnType::Decimal === $type                                      => ['numeric', null, $col->precision, $col->scale ?? 0],
            ColumnType::Char === $type                                         => ['char', $col->length, null, null],
            ColumnType::VarChar === $type                                      => ['varchar', $col->length, null, null],
            ColumnType::Json === $type                                         => ['jsonb', null, null, null],
            ColumnType::Enum === $type                                         => ['text', null, null, null],
            $col->isBinary                                                     => ['bytea', null, null, null],
            ColumnType::Date === $type                                         => ['date', null, null, null],
            ColumnType::DateTime === $type, ColumnType::Timestamp === $type    => ['timestamp', null, ($col->precision ?? 0) ?: 6, null],
            default                                                            => ['text', null, null, null],
        };

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: false,
            length: $length,
            precision: $p,
            scale: $s,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, self::desiredDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generatedAs),
            members: null,
        ));
    }

    #[\Override]
    public function normalizeLive(LiveColumn $col): NormalizedColumn
    {
        $raw = trim($col->rawType);

        if (1 === preg_match('/^(varchar|bpchar)\((\d+)\)$/', $raw, $m)) {
            $parsed = ['bpchar' === $m[1] ? 'char' : 'varchar', (int) $m[2], null, null];
        } elseif (1 === preg_match('/^numeric\((\d+),\s*(\d+)\)$/', $raw, $m)) {
            $parsed = ['numeric', null, (int) $m[1], (int) $m[2]];
        } elseif (1 === preg_match('/^(timestamp|timestamptz)(?:\((\d+)\))?$/', $raw, $m)) {
            $parsed = [$m[1], null, isset($m[2]) ? (int) $m[2] : 6, null];
        } else {
            $family = match ($raw) {
                'int2'          => 'smallint',
                'int4'          => 'integer',
                'int8'          => 'bigint',
                'bool'          => 'bool',
                'float4'        => 'real',
                'float8'        => 'double',
                'jsonb', 'json' => $raw,
                'bytea'         => 'bytea',
                'text'          => 'text',
                'date'          => 'date',
                default         => null,
            };
            if (null === $family) {
                return NormalizedColumn::unsure("unrecognized PostgreSQL column type: {$raw}");
            }
            $parsed = [$family, null, null, null];
        }

        [$family, $length, $p, $s] = $parsed;

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: false,
            length: $length,
            precision: $p,
            scale: $s,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, $this->liveDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generationExpression),
            members: null,
        ));
    }

    private function liveDefault(LiveColumn $col): ?string
    {
        if ($col->autoIncrement || null !== $col->generationExpression) {
            return null;
        }
        $raw = $col->rawDefault;
        if (null === $raw || 'null' === strtolower($raw)) {
            return null;
        }
        // Strip PG's type cast suffix: `'draft'::text`, `'0'::smallint`, `NULL::character varying`.
        $uncast = preg_replace('/::[a-z_ ]+(\(\d+(,\s*\d+)?\))?$/i', '', trim($raw)) ?? $raw;
        if ('null' === strtolower($uncast)) {
            return null;
        }
        if (1 === preg_match('/^(current_timestamp(\(\d*\))?|now\(\))$/i', $uncast)) {
            return self::canonExpr($uncast);
        }
        // PG reports boolean defaults as true/false keywords; canon is 1/0 (matching the desired side).
        if ('true' === strtolower($uncast)) {
            return '1';
        }
        if ('false' === strtolower($uncast)) {
            return '0';
        }

        return self::stripOuterQuotes($uncast);
    }
}
