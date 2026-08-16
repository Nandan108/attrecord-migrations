<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

/**
 * The canonical comparison tuple (the design contract §4.2): both the attribute-derived desired
 * column and the introspected live column reduce to this shape — in the *same dialect's*
 * vocabulary — so equality is meaningful and every facet difference is nameable.
 *
 * The vocabulary is dialect-local by design: on PostgreSQL both sides collapse `unsigned` to
 * false (PG has none) and binary lengths to null (BYTEA is unsized); on SQLite the integer
 * families all collapse to `integer` (affinity is all the engine stores). A dialect cannot drift
 * in a way it cannot represent, so collapsing on *both* sides loses nothing.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the differ and by consumers.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class ColumnTuple
{
    /**
     * @param list<string>|null $members enum/SET members, or null when this side cannot see them.
     *                                   Legible in the type string on MySQL-family; recovered from
     *                                   the `chk_<column>_enum` CHECK constraint on PostgreSQL and
     *                                   SQLite, which have no native ENUM type.
     */
    public function __construct(
        /** Canonical type family in the dialect's own vocabulary, lowercased: `smallint`, `varchar`, `bool`, `jsonb`, `text`, … */
        public readonly string $type,
        public readonly bool $unsigned,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly bool $nullable,
        /**
         * Canonical default: `null` = no effective default (covers both "none" and `DEFAULT NULL` —
         * for a nullable column they are indistinguishable in effect, and for a NOT NULL column
         * `DEFAULT NULL` is invalid anyway); a string = the canonical literal (unquoted, uncasted)
         * or canonical expression spelling (e.g. `CURRENT_TIMESTAMP(6)`).
         */
        public readonly ?string $default,
        public readonly bool $autoIncrement,
        /** Loosely-normalized generation expression (case/quote/whitespace-insensitive), or null. */
        public readonly ?string $generated,
        public readonly ?array $members,
    ) {
    }

    /**
     * Name every facet on which two tuples differ — the classifier's input. Empty = identical.
     *
     * Two facets are skipped when **both** sides are generated columns, because on a generated
     * column the engine, not the declaration, owns them:
     *
     * - *nullability* — MySQL and MariaDB report a generated column as nullable unless it was
     *   explicitly declared `NOT NULL`, whatever the declaration implied. Comparing it makes
     *   every generated column read as permanently drifted the moment it is created.
     * The *expression* is compared, and a difference is always routed to **Manual** — never an
     * automatic ALTER. Engines store their own rewriting of what you wrote, so
     * {@see AbstractColumnNormalizer::looseExpr()} absorbs case, quoting, whitespace and a
     * redundant outer bracket pair before comparing. That is enough for the shapes seen in
     * practice, and where it is not, the cost is a visible advisory naming the column rather than
     * a wrong statement.
     *
     * Skipping it instead — the earlier behaviour — bought silence at a price only paid later: a
     * corrected expression produced *no planned change at all*, so the repair reached new installs
     * and no existing one, indefinitely and without a word. Between a false Manual and a silent
     * non-repair, this package's bias points at the one that speaks.
     *
     * *Members* are skipped when **either** side is null, null meaning "cannot see the members"
     * rather than "has none". It is the same fail-safe direction, and here it is load-bearing: on
     * PostgreSQL and SQLite the member list is read back out of a CHECK constraint, and a body
     * this package cannot parse yields null. Diffing that would plan a constraint swap whose
     * result is still unparseable — a plan that never converges, breaking the invariant that a
     * freshly created table re-plans empty. A column that is genuinely an enum on both sides
     * parses on both sides, which is the case the detection exists for.
     *
     * @return list<string>
     */
    public function diffFacets(self $other): array
    {
        $bothGenerated = null !== $this->generated && null !== $other->generated;

        $facets = [];
        foreach ([
            'type'          => [$this->type, $other->type],
            'unsigned'      => [$this->unsigned, $other->unsigned],
            'length'        => [$this->length, $other->length],
            'precision'     => [$this->precision, $other->precision],
            'scale'         => [$this->scale, $other->scale],
            'nullable'      => [$this->nullable, $other->nullable],
            'default'       => [$this->default, $other->default],
            'autoIncrement' => [$this->autoIncrement, $other->autoIncrement],
            'generated'     => [$this->generated, $other->generated],
            'members'       => [$this->members, $other->members],
        ] as $facet => [$a, $b]) {
            if ($bothGenerated && 'nullable' === $facet) {
                continue;
            }
            if ('members' === $facet && (null === $a || null === $b)) {
                continue;
            }
            if ($a !== $b) {
                $facets[] = $facet;
            }
        }

        return $facets;
    }

    public function equals(self $other): bool
    {
        return [] === $this->diffFacets($other);
    }
}
