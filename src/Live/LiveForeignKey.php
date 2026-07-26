<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One foreign-key constraint as reported by the live database. Referential actions are stored
 * uppercased (`RESTRICT`, `CASCADE`, `SET NULL`, `NO ACTION`, `SET DEFAULT`) as engines report
 * them; `NO ACTION` vs `RESTRICT` equivalence is the normalizer's concern, not this DTO's.
 */
/** @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers. */
final class LiveForeignKey
{
    /**
     * @param list<string> $localColumns      in constraint order
     * @param list<string> $referencedColumns in constraint order, paired with $localColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $localColumns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
        public readonly string $onDelete,
        public readonly string $onUpdate,
    ) {
    }
}
