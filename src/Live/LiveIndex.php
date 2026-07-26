<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One secondary index or unique key as reported by the live database. The primary key is *not*
 * represented here — it lives on {@see LiveTable::$primaryKey}.
 */
/** @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers. */
final class LiveIndex
{
    /** @param list<string> $columns column names in index order */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
    ) {
    }
}
