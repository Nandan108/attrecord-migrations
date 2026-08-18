<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One live table: the introspected mirror of what `TableSchema` declares. A deliberately *dumber*
 * model than `TableSchema` — no PHP-side fields (propertyName, caster, phpType), no validation:
 * it records what the catalog said, nothing more.
 *
 * `$checks` is a faithful mirror in the same way: it holds **every** CHECK constraint the engine
 * reports, including the `chk_<column>_enum` ones the producer writes to carry an enum column's
 * members on PostgreSQL and SQLite. Telling those apart from table-level rules is the differ's job,
 * not this DTO's.
 */
/** @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers. */
final class LiveTable
{
    /**
     * The constraint maps are keyed by name — declared `array-key` rather than `string` because PHP
     * makes that decision, not us: a numeric name (`1`, which is how some engines name an
     * unnamed constraint) silently becomes an int key. Each object still carries its own `name`
     * as a string; read that rather than the key when the type matters.
     *
     * @param array<array-key, LiveColumn>     $columns     keyed by column name, in ordinal order
     * @param list<string>                     $primaryKey  PK column names in key order ([] = no PK; >1 = composite, which the differ classifies Manual)
     * @param array<array-key, LiveIndex>      $indexes     keyed by index name (unique keys included, PK excluded)
     * @param array<array-key, LiveForeignKey> $foreignKeys keyed by constraint name
     * @param array<array-key, string>         $checks      CHECK constraint name → body as the engine reports it
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $primaryKey,
        public readonly array $indexes,
        public readonly array $foreignKeys,
        public readonly array $checks = [],
    ) {
    }
}
