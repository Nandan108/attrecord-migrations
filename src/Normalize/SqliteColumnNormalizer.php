<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\AttrecordMigrations\Live\LiveColumn;

/**
 * SQLite canonicalization: the coarsest of the three, because SQLite only stores affinity — the
 * integer families collapse to `integer`, decimals to `numeric` (no precision/scale), strings and
 * temporals to `text` (no lengths), binaries to `blob`. Both sides collapse identically, so SQLite
 * cannot false-drift on distinctions it does not store — and equally cannot detect drift within
 * them (a documented v0.1 limitation; MySQL/PG catch those).
 *
 * The live side applies SQLite's own type-affinity algorithm, so tables created outside the
 * attrecord producer (arbitrary declared types like `VARCHAR(64)`) still normalize.
 *
 * Enum is the one place SQLite sees *more* than its affinity: with no native ENUM type the
 * producer puts the member list in a `chk_<column>_enum` CHECK constraint, and SQLite stores DDL
 * verbatim, so {@see EnumCheckParser} reads back exactly what was written. Member drift is
 * therefore detected here — though not auto-applied, SQLite having no `DROP CONSTRAINT`
 * (see SqliteAlterEmitter).
 */
final class SqliteColumnNormalizer extends AbstractColumnNormalizer
{
    #[\Override]
    public function normalizeDesired(ColumnDefinition $col): NormalizedColumn
    {
        $type = $col->type;

        $family = match (true) {
            $col->isBool, $col->isInteger                             => 'integer',
            ColumnType::Float === $type, ColumnType::Double === $type => 'real',
            ColumnType::Decimal === $type                             => 'numeric',
            $col->isBinary                                            => 'blob',
            default                                                   => 'text',
        };

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: false,
            length: null,
            precision: null,
            scale: null,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, self::desiredDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generatedAs),
            members: ColumnType::Enum === $type ? ($col->enumValues ?? null) : null,
        ));
    }

    #[\Override]
    public function normalizeLive(LiveColumn $col): NormalizedColumn
    {
        $family = self::affinity($col->rawType);

        return NormalizedColumn::ok(new ColumnTuple(
            type: $family,
            unsigned: false,
            length: null,
            precision: null,
            scale: null,
            nullable: $col->nullable,
            default: self::canonDefaultForFamily($family, $this->liveDefault($col)),
            autoIncrement: $col->autoIncrement,
            generated: self::looseExpr($col->generationExpression),
            members: null !== $col->rawEnumCheck ? EnumCheckParser::members($col->rawEnumCheck) : null,
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
        if (1 === preg_match('/^current_timestamp$/i', $raw)) {
            return 'CURRENT_TIMESTAMP';
        }

        return self::stripOuterQuotes($raw); // pragma reports defaults as written in the DDL (strings quoted)
    }

    /** SQLite's documented type-affinity algorithm (§3.1 of the SQLite datatype doc). */
    private static function affinity(string $declaredType): string
    {
        $t = strtolower($declaredType);

        return match (true) {
            str_contains($t, 'int')                                                          => 'integer',
            str_contains($t, 'char') || str_contains($t, 'clob') || str_contains($t, 'text') => 'text',
            '' === $t || str_contains($t, 'blob')                                            => 'blob',
            str_contains($t, 'real') || str_contains($t, 'floa') || str_contains($t, 'doub') => 'real',
            default                                                                          => 'numeric',
        };
    }
}
