<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

/**
 * Safety classification of a planned change (the design contract §3.1). `apply(allow:)` treats this
 * as a **ceiling**: each class names how much authority running it needs, and a run applies
 * everything at or below the ceiling it was given.
 *
 * The ladder is `Safe → Destructive → Assisted`, with `Manual` off it entirely:
 *
 * | Class | Statement known? | Runs when |
 * | --- | --- | --- |
 * | `Safe` | yes | always — the default ceiling |
 * | `Destructive` | yes | ceiling is `Destructive` or higher |
 * | `Assisted` | yes | ceiling is `Assisted` — an operator has accepted responsibility |
 * | `Manual` | **no** | never |
 *
 * **`Assisted` exists because `Manual` was two things wearing one label.** Some changes were
 * withheld because no single safe statement exists — a changed primary key, a SQLite column rebuild
 * — and others because the statement was perfectly well known but too consequential to run
 * unattended, such as adopting a changed generation expression. Only the second kind can ever be
 * offered to an operator as "here is the exact SQL, press to apply", and conflating them meant it
 * could not be: `Manual` carries no statements, so there was nothing to press.
 *
 * `Assisted` is therefore not "more destructive than `Destructive`" — it is *more deliberate*. It
 * sits higher on the ladder because reaching it requires a specific decision rather than a policy,
 * typically a human who has taken a backup.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
enum ChangeClass: string
{
    /** Additive or metadata-only; cannot destroy data (may loudly reject existing rows — see PlannedChange::$mayRejectExistingRows). */
    case Safe = 'safe';

    /** Potentially lossy (drops, narrowing conversions, nullable tightening); requires explicit opt-in. */
    case Destructive = 'destructive';

    /**
     * The statement is known and correct, but consequential enough that it is never run by policy —
     * only by a person who has chosen to. Adopting a changed generation expression is the canonical
     * case: it is a plain `MODIFY COLUMN`, and rebuilding the column across a populated table is
     * exactly the kind of thing to do behind a backup rather than on the next page load.
     */
    case Assisted = 'assisted';

    /** No single safe statement exists — a changed PK, a SQLite rebuild, a facet the planner cannot read. Carries no SQL, and never runs. */
    case Manual = 'manual';

    /**
     * Position on the authorisation ladder. `Manual` is deliberately unreachable rather than merely
     * highest: it is not "the most dangerous", it is "there is nothing to execute".
     */
    private function rank(): int
    {
        return match ($this) {
            self::Safe        => 0,
            self::Destructive => 1,
            self::Assisted    => 2,
            self::Manual      => \PHP_INT_MAX,
        };
    }

    /** Whether a change of this class may execute under the given ceiling. Manual never executes. */
    public function withinCeiling(self $ceiling): bool
    {
        if (self::Manual === $this) {
            return false;
        }

        return $this->rank() <= $ceiling->rank();
    }
}
