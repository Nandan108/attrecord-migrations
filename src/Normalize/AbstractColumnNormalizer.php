<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

use Nandan108\Attrecord\Schema\ColumnDefinition;

/**
 * Shared canonicalization helpers for the per-dialect normalizers: quote/cast stripping,
 * expression spelling, and the desired-side default canon (which is dialect-independent —
 * dialects differ in *types*, not in how a PHP literal becomes a canonical default string).
 */
abstract class AbstractColumnNormalizer implements ColumnNormalizer
{
    /**
     * Canonical default for the desired side: null = no effective default. An auto-increment
     * column never carries one; `defaultExpr: 'NULL'` collapses to none (same effect); a literal
     * becomes its plain string form (bool as 1/0, matching how every engine reports it back).
     */
    protected static function desiredDefault(ColumnDefinition $col): ?string
    {
        if ($col->autoIncrement || $col->isGenerated) {
            return null;
        }
        if (null !== $col->defaultExpr) {
            $expr = self::canonExpr($col->defaultExpr);

            return 'NULL' === $expr ? null : $expr;
        }
        if (null === $col->default) {
            return null;
        }
        if (\is_bool($col->default)) {
            return $col->default ? '1' : '0';
        }

        return (string) $col->default;
    }

    /**
     * Canonical spelling for a default *expression*: trimmed, uppercased, inner whitespace
     * collapsed; `now()` (PG's report of CURRENT_TIMESTAMP) unified. Precision suffixes are kept —
     * `CURRENT_TIMESTAMP(6)` and `CURRENT_TIMESTAMP` are genuinely different defaults.
     */
    protected static function canonExpr(string $expr): string
    {
        $canon = strtoupper(trim(preg_replace('/\s+/', ' ', $expr) ?? $expr));
        if ('NOW()' === $canon) {
            return 'CURRENT_TIMESTAMP';
        }
        // current_timestamp() (MariaDB spelling) ≡ CURRENT_TIMESTAMP; keep an explicit precision.
        if (1 === preg_match('/^CURRENT_TIMESTAMP(?:\((\d*)\))?$/', $canon, $m)) {
            $precision = $m[1] ?? '';

            return '' === $precision || '0' === $precision ? 'CURRENT_TIMESTAMP' : "CURRENT_TIMESTAMP({$precision})";
        }

        return $canon;
    }

    /**
     * Canonicalize a default for numeric families: engines re-render numeric literals
     * (`'0.00'` → `0` on PG/SQLite, kept verbatim on MariaDB), so both sides must reduce to one
     * spelling before comparison. Pure-integer strings keep integer form; decimal forms go through
     * float canon. Non-numeric strings (and non-numeric families) pass through untouched.
     */
    protected static function canonDefaultForFamily(string $family, ?string $default): ?string
    {
        if (null === $default || !\in_array($family, [
            'bool', 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'real', 'double', 'float',
        ], true) || !is_numeric($default)) {
            return $default;
        }
        if (1 === preg_match('/^-?\d+$/', $default)) {
            return (string) (int) $default;
        }

        return (string) (float) $default;
    }

    /** Strip one layer of single quotes (with `''` unescape) if the value is quoted; pass through otherwise. */
    protected static function stripOuterQuotes(string $value): string
    {
        if (\strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * Loose canon for generation expressions: comparing SQL expressions across the attribute
     * spelling and the engine's re-rendering is inherently fuzzy (engines re-quote identifiers and
     * literals), so this strips identifier quoting and whitespace and lowercases — good enough to
     * say "same"; anything that still differs is the differ's business to surface, never to guess
     * about.
     */
    protected static function looseExpr(?string $expr): ?string
    {
        if (null === $expr || '' === trim($expr)) {
            return null;
        }

        return self::stripRedundantParens(strtolower(str_replace(['`', '"', ' ', "\t", "\n"], '', $expr)));
    }

    /**
     * Drop bracket pairs that cannot change what the expression means.
     *
     * An engine stores its own re-printing of the expression, and each one brackets to its own
     * taste. MariaDB drops a redundant outer pair, so `(closed_at IS NULL)` reads back as
     * `closed_at is null`; MySQL adds one around a compound function argument, so
     * `GREATEST(0, a - b)` reads back as `greatest(0,(a - b))`. Neither difference means anything,
     * and both would otherwise read as drift on a column that is exactly as declared.
     *
     * A pair is removed only where **no operator can bind across it**, which is the one case where
     * removal is safe without knowing SQL's precedence table: when the characters immediately
     * either side are a bracket, a comma, or the ends of the string. That covers a whole
     * expression, `(a)`, and a complete function argument, `f(x,(a-b))` — and refuses everything
     * else, so `(a+b)*c` keeps the brackets that make it what it is, and `(a)-(b)` is left alone.
     * Nested pairs come off one at a time until none qualifies.
     *
     * Brackets inside a string literal are text, not structure, so literals are skipped over
     * (doubled quotes and all). An unbalanced expression is returned untouched — it is not ours to
     * repair, and the differ reporting it as changed is the correct outcome.
     */
    private static function stripRedundantParens(string $expr): string
    {
        while (null !== ($pair = self::removableParens($expr))) {
            [$open, $close] = $pair;
            $expr = substr($expr, 0, $open).substr($expr, $open + 1, $close - $open - 1).substr($expr, $close + 1);
        }

        return $expr;
    }

    /**
     * Position of the first bracket pair {@see stripRedundantParens} may remove, or null if none.
     *
     * @return array{int, int}|null
     */
    private static function removableParens(string $expr): ?array
    {
        /** @psalm-var list<int> $open */
        $open = [];
        $n = \strlen($expr);

        for ($i = 0; $i < $n; ++$i) {
            if ("'" === $expr[$i]) {
                $i = self::endOfLiteral($expr, $i);
                continue;
            }

            if ('(' === $expr[$i]) {
                $open[] = $i;
                continue;
            }

            if (')' !== $expr[$i]) {
                continue;
            }

            $start = array_pop($open);
            if (null === $start) {
                return null; // unbalanced — leave the expression exactly as it is
            }

            $before = $start > 0 ? $expr[$start - 1] : '';
            $after = $i + 1 < $n ? $expr[$i + 1] : '';

            // A bracket or comma either side means there is no operator to bind across the pair,
            // and an empty side means there is nothing there at all. A letter before it makes it a
            // function's own bracket; anything else is an operator whose grouping it decides.
            if (\in_array($before, ['', '(', ','], true) && \in_array($after, ['', ')', ','], true)) {
                return [$start, $i];
            }
        }

        return null;
    }

    /** Index of the closing quote of the literal opening at `$start`, or the last index if unterminated. */
    private static function endOfLiteral(string $expr, int $start): int
    {
        $n = \strlen($expr);
        for ($i = $start + 1; $i < $n; ++$i) {
            if ("'" !== $expr[$i]) {
                continue;
            }
            if ($i + 1 < $n && "'" === $expr[$i + 1]) {
                ++$i; // an escaped quote, not the end

                continue;
            }

            return $i;
        }

        return $n - 1;
    }

    /** Parse the members out of a MySQL-style `enum('a','b')` / `set('a','b')` type string. */
    /** @return list<string>|null */
    protected static function parseMembers(string $rawType): ?array
    {
        if (0 === preg_match('/^(?:enum|set)\((.*)\)$/i', trim($rawType), $m)) {
            return null;
        }
        if (0 === preg_match_all("/'((?:[^']|'')*)'/", $m[1], $members)) {
            return [];
        }

        return array_map(static fn (string $v): string => str_replace("''", "'", $v), $members[1]);
    }
}
