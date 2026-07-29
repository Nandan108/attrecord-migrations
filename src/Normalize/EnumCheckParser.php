<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

/**
 * Recovers an enum column's member list from the body of its `chk_<column>_enum` CHECK constraint.
 *
 * On PostgreSQL and SQLite the producer has no native ENUM type to put the members in, so it
 * writes them into a CHECK constraint. That makes the members *enforced* but, until this parser,
 * *unreadable*: the differ compared two nulls and reported no drift, so adding a case to a PHP
 * enum converged clean while the constraint kept rejecting the new value at runtime.
 *
 * Scope is deliberately narrow — this parses the shapes an engine produces from the one expression
 * the producer emits (`"col" IN ('a', 'b')`), not CHECK expressions in general. That is what keeps
 * it honest: a general expression differ would have to reconcile PG's rewrites with the declared
 * text and would re-plan forever, the same trap generated columns fell into. Anything unrecognized
 * returns null, which the normalizer treats as "cannot see the members" — the pre-existing blind
 * spot, never a wrong answer.
 *
 * The four renderings observed (PostgreSQL 16 / SQLite 3):
 *
 *     "col" IN ('a', 'b')                                              -- SQLite, verbatim
 *     CHECK ((col = ANY (ARRAY['a'::text, 'b'::text])))                -- PG, TEXT column
 *     CHECK ((col = 'only'::text))                                     -- PG, single member
 *     CHECK (((col)::text = ANY ((ARRAY['a'::character varying])::text[])))  -- PG, VARCHAR column
 *
 * @internal
 */
final class EnumCheckParser
{
    /**
     * @param string $body the constraint body as the engine reports it
     *
     * @return list<string>|null members in declared order, or null when the shape is unrecognized
     */
    public static function members(string $body): ?array
    {
        // Take everything after the first `=` or ` IN `, which is where the member list starts in
        // every observed rendering. Splitting on the operator rather than matching the whole
        // expression means the left-hand side may be `col`, `"col"` or `(col)::text` without
        // needing a pattern per variant.
        if (1 === preg_match('/\bIN\s*\((.*)\)\s*\)?\s*$/is', $body, $m)) {
            $list = $m[1];
        } elseif (1 === preg_match('/=\s*ANY\s*\(\s*\(?\s*ARRAY\[(.*)\]/is', $body, $m)) {
            $list = $m[1];
        } elseif (1 === preg_match('/=\s*(\'(?:[^\']|\'\')*\')/s', $body, $m)) {
            // Single-member enums collapse to a plain equality — there is no array to find.
            $list = $m[1];
        } else {
            return null;
        }

        return self::splitLiterals($list);
    }

    /**
     * Split a comma-separated SQL literal list into its values.
     *
     * Hand-scanned rather than `explode(',')` because a member may legitimately contain a comma or
     * a closing parenthesis (`'a)b'`, `'c,d'` both round-trip through PG), and `''` is an escaped
     * quote inside a literal, not the end of one.
     *
     * @return list<string>|null null when a non-literal element appears — an expression this
     *                           parser has no business guessing at
     */
    private static function splitLiterals(string $list): ?array
    {
        $members = [];
        $length = \strlen($list);
        $i = 0;

        while ($i < $length) {
            // Skip separators and whitespace between literals.
            if (1 === preg_match('/[\s,]/', $list[$i])) {
                ++$i;
                continue;
            }

            // End of the list. Reached rather than errored on, because the captures above run to
            // the *last* bracket so that a member containing one (`'a]b'`) stays intact — which
            // leaves the array's own closer, and PG's `])::text[]` tail, to be stopped at here.
            if (']' === $list[$i] || ')' === $list[$i]) {
                break;
            }

            if ("'" !== $list[$i]) {
                return null; // a non-literal member — bail rather than guess
            }

            ++$i; // opening quote
            $value = '';
            $closed = false;
            while ($i < $length) {
                if ("'" === $list[$i]) {
                    if ($i + 1 < $length && "'" === $list[$i + 1]) {
                        $value .= "'"; // doubled quote = one literal quote
                        $i += 2;
                        continue;
                    }
                    ++$i; // closing quote
                    $closed = true;
                    break;
                }
                $value .= $list[$i];
                ++$i;
            }

            if (!$closed) {
                return null; // unterminated literal — not a shape we emitted
            }

            $members[] = $value;

            // Drop a trailing cast (`::text`, `::character varying`) before the next separator.
            if (1 === preg_match('/\G::\s*[a-z_ ]+(\(\d+(,\s*\d+)?\))?/i', $list, $cast, 0, $i)) {
                $i += \strlen($cast[0]);
            }
        }

        return [] === $members ? null : $members;
    }
}
