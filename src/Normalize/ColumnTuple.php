<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

/**
 * The canonical comparison tuple (arch-migrations.md §4.2): both the attribute-derived desired
 * column and the introspected live column reduce to this shape — in the *same dialect's*
 * vocabulary — so equality is meaningful and every facet difference is nameable.
 *
 * The vocabulary is dialect-local by design: on PostgreSQL both sides collapse `unsigned` to
 * false (PG has none) and binary lengths to null (BYTEA is unsized); on SQLite the integer
 * families all collapse to `integer` (affinity is all the engine stores). A dialect cannot drift
 * in a way it cannot represent, so collapsing on *both* sides loses nothing.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the differ and by consumers.
 */
final class ColumnTuple
{
    /** @param list<string>|null $members enum/SET members, when the dialect can see them on both sides (MySQL-family only) */
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
     * @return list<string>
     */
    public function diffFacets(self $other): array
    {
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
