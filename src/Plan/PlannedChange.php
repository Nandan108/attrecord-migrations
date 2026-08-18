<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

/**
 * One classified change in a {@see Plan}: what would be done (`$statements` — empty for Manual,
 * which is never executed), to which table/subject, how it is classified, and *why* — the reason is
 * part of the contract, because a plan is an inspection surface first and a script second.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the applier and by consumers.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class PlannedChange
{
    /** @param list<string> $statements executable SQL, in order; [] for Manual changes */
    public function __construct(
        public readonly string $table,
        /** Machine-readable kind: create_table, add_column, modify_column, rename_column, drop_column, create_index, drop_index, add_foreign_key, drop_foreign_key, add_check, drop_check, manual. */
        public readonly string $kind,
        /** The column/index/constraint the change targets ('' for whole-table changes). */
        public readonly string $subject,
        public readonly ChangeClass $class,
        public readonly array $statements,
        /** Human-readable rationale: what drifted (named facets) or why this is Manual. */
        public readonly string $reason,
        /**
         * True when the change is Safe but can loudly reject existing rows (ADD UNIQUE on
         * duplicate data, ADD FK on orphans, NOT NULL tightening): an atomic failure, never
         * silent loss — see the design contract §3.1.
         */
        public readonly bool $mayRejectExistingRows = false,
    ) {
    }
}
