<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

/**
 * The inspectable output of `plan()`: an ordered list of classified changes. Pure data — building
 * a Plan executes nothing.
 *
 * @psalm-suppress PossiblyUnusedMethod Public inspection surface.
 */
final class Plan
{
    /** @param list<PlannedChange> $changes */
    public function __construct(
        public readonly array $changes,
        /** Fingerprint of the desired model set this plan was computed from (see Fingerprint). */
        public readonly string $fingerprint = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    /** @return list<PlannedChange> */
    public function byClass(ChangeClass $class): array
    {
        return array_values(array_filter($this->changes, static fn (PlannedChange $c): bool => $class === $c->class));
    }

    public function hasDestructive(): bool
    {
        return [] !== $this->byClass(ChangeClass::Destructive);
    }

    public function hasManual(): bool
    {
        return [] !== $this->byClass(ChangeClass::Manual);
    }

    /** Changes whose SQL is known but which only run under an explicit `Assisted` ceiling. */
    public function hasAssisted(): bool
    {
        return [] !== $this->byClass(ChangeClass::Assisted);
    }

    /** Anything beyond the default Safe ceiling — i.e. requiring opt-in, a person, or both. */
    public function hasBeyondSafe(): bool
    {
        return $this->hasDestructive() || $this->hasAssisted() || $this->hasManual();
    }

    /**
     * All executable SQL in plan order (Manual changes contribute nothing).
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $out = [];
        foreach ($this->changes as $change) {
            foreach ($change->statements as $sql) {
                $out[] = $sql;
            }
        }

        return $out;
    }
}
