<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Live;

/**
 * One column as reported by the live database, in near-raw form.
 *
 * Introspectors do **no interpretation** beyond mapping their catalog's rows into this shape —
 * type strings, default expressions and generation expressions are stored as the engine reports
 * them (lowercased where the engine is case-insensitive). All aliasing, quoting and cross-dialect
 * canonicalization is the normalizer's job (the design contract §4.2); keeping this DTO raw is what
 * makes the normalizers pure string-in/tuple-out units.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the diff pipeline and by consumers.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
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
        /**
         * Body of the CHECK constraint named `chk_<column>_enum`, exactly as the engine reports it,
         * or null when the column has none. This is how enum members stay visible on the dialects
         * with no native ENUM type (PostgreSQL, SQLite), where the producer stores the member list
         * in a CHECK rather than in the column type.
         *
         * Deliberately un-parsed here — engines rewrite the body (PG turns `col IN ('a','b')` into
         * `col = ANY (ARRAY['a'::text, 'b'::text])`), and untangling that is the normalizer's job,
         * like every other canonicalization. Null on MySQL-family, whose members are legible in the
         * type string itself.
         */
        public readonly ?string $rawEnumCheck = null,
    ) {
    }
}
