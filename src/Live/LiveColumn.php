<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One column as reported by the live database, in near-raw form.
 *
 * Introspectors do **no interpretation** beyond mapping their catalog's rows into this shape —
 * type strings, default expressions and generation expressions are stored as the engine reports
 * them (lowercased where the engine is case-insensitive). All aliasing, quoting and cross-dialect
 * canonicalization is the normalizer's job (arch-migrations.md §4.2); keeping this DTO raw is what
 * makes the normalizers pure string-in/tuple-out units.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers.
 */
final class LiveColumn
{
    public function __construct(
        public readonly string $name,
        /** Full type as reported, lowercased — e.g. `smallint(5) unsigned`, `character varying(64)`, `enum('a','b')`, `TEXT`. */
        public readonly string $rawType,
        public readonly bool $nullable,
        /** Default as reported by the catalog, or null when the column has no default. Quoting/casts vary per engine. */
        public readonly ?string $rawDefault,
        public readonly bool $autoIncrement,
        /** Generation expression for a generated column, or null. */
        public readonly ?string $generationExpression = null,
        /** Raw ON UPDATE expression (MySQL-family EXTRA), or null. Captured but not diffed in v0.1 (see design §3.1 Manual notes). */
        public readonly ?string $rawOnUpdate = null,
    ) {
    }
}
