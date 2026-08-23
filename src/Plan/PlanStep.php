<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

/**
 * A consumer transform attached to one planned change, to run immediately before or after it.
 *
 * The case it exists for is the transform whose marker *is* the schema delta: wrap a `TEXT`
 * column's values to JSON **before** the `MODIFY … JSON` that would otherwise reject them, or
 * backfill a freshly-added column **after** the `ADD`. Both need to happen at a particular point in
 * the apply order, which is the one thing a run-once data step cannot express — see
 * {@see \Nandan108\AttrecordMigrations\SchemaMigrator::dataStep()} for the other half of the data
 * boundary, where there is no delta to attach to.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md §6.1
 */
final class PlanStep
{
    /**
     * @param string   $selector  which change this attaches to, `"kind table.subject"` — e.g.
     *                            `"modify_column orders.payload"` — or `"kind table"` for a change
     *                            with no subject. The vocabulary is `PlannedChange::$kind`, the
     *                            same strings a plan prints.
     * @param bool     $runBefore true to run ahead of the change's statements, false after
     * @param \Closure $run       `fn (DbSession $session): void`
     */
    public function __construct(
        public readonly string $selector,
        public readonly bool $runBefore,
        public readonly \Closure $run,
    ) {
    }

    /** Whether this step is attached to `$change`. */
    public function matches(PlannedChange $change): bool
    {
        return $this->selector === self::selectorFor($change);
    }

    /** The selector naming a given change — the same spelling {@see matches()} compares against. */
    public static function selectorFor(PlannedChange $change): string
    {
        return '' === $change->subject
            ? $change->kind.' '.$change->table
            : $change->kind.' '.$change->table.'.'.$change->subject;
    }
}
