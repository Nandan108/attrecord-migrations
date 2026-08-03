<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

/**
 * Safety classification of a planned change (the design contract §3.1). `apply(allow:)` treats this
 * as a ceiling: Safe applies by default, Destructive only by explicit opt-in, and **Manual is never
 * auto-applied** — it exists to be *seen* (logged, surfaced in diagnostics), not executed.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
enum ChangeClass: string
{
    /** Additive or metadata-only; cannot destroy data (may loudly reject existing rows — see PlannedChange::$mayRejectExistingRows). */
    case Safe = 'safe';

    /** Potentially lossy (drops, narrowing conversions, nullable tightening); requires explicit opt-in. */
    case Destructive = 'destructive';

    /** The planner is unsure, or the change needs a human (PK changes, generated-expr drift, SQLite rebuilds). Never auto-applied. */
    case Manual = 'manual';

    /** Whether a change of this class may execute under the given ceiling. Manual never executes. */
    public function withinCeiling(self $ceiling): bool
    {
        return match ($this) {
            self::Safe        => true,
            self::Destructive => self::Destructive === $ceiling,
            self::Manual      => false,
        };
    }
}
