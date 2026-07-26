<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One live table: the introspected mirror of what `TableSchema` declares. A deliberately *dumber*
 * model than `TableSchema` — no PHP-side fields (propertyName, caster, phpType), no validation:
 * it records what the catalog said, nothing more.
 */
/** @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers. */
final class LiveTable
{
    /**
     * @param array<string, LiveColumn>     $columns     keyed by column name, in ordinal order
     * @param list<string>                  $primaryKey  PK column names in key order ([] = no PK; >1 = composite, which the differ classifies Manual)
     * @param array<string, LiveIndex>      $indexes     keyed by index name (unique keys included, PK excluded)
     * @param array<string, LiveForeignKey> $foreignKeys keyed by constraint name
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $primaryKey,
        public readonly array $indexes,
        public readonly array $foreignKeys,
    ) {
    }
}
