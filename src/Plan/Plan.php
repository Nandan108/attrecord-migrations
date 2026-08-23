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
    /**
     * @param list<PlannedChange> $changes
     * @param list<PlanStep>      $steps   consumer transforms attached to individual changes
     */
    public function __construct(
        public readonly array $changes,
        /** Fingerprint of the desired model set this plan was computed from (see Fingerprint). */
        public readonly string $fingerprint = '',
        public readonly array $steps = [],
    ) {
    }

    /**
     * A copy of this plan with a consumer transform attached before or after one change.
     *
     * For the transform whose marker *is* a schema delta: wrap a column's values before the ALTER
     * that would reject them, or backfill a freshly-added column after the ADD. Placement is the
     * consumer's call because both orderings are real.
     *
     *     $plan = $plan->withStep(
     *         before: 'modify_column orders.payload',
     *         run: fn (DbSession $s) => $s->exec("UPDATE orders SET payload = JSON_OBJECT('data', payload)"),
     *     );
     *
     * The selector is `"kind table.subject"` — or `"kind table"` for a change with no subject — in
     * the same vocabulary a plan prints, so it can be read straight off `plan()`'s output. Exactly
     * one of `before:` / `after:` is required.
     *
     * **A selector matching no change in this plan is a no-op, not an error**, and it has to be:
     * the second time a converged install boots, the change has already been applied and the plan
     * is empty, while the consumer's code still attaches its step. The mistake that *can* be caught
     * — a kind that does not exist — is caught here, where it is written. Unmatched steps are
     * recorded in the run ledger, so "my backfill never ran" is answerable after the fact.
     *
     * **The pair is not atomic on MySQL or MariaDB.** DDL auto-commits there, so a step and its
     * change cannot share a transaction, and a failure between them leaves one applied and not the
     * other. PostgreSQL and SQLite have transactional DDL and a caller may wrap the whole apply.
     * Write steps to be re-runnable where the transform allows it.
     *
     * @param \Closure(\Nandan108\Attrecord\DbSession): void $run
     *
     * @throws \InvalidArgumentException on a malformed selector, an unknown kind, or neither/both of before and after
     */
    public function withStep(\Closure $run, ?string $before = null, ?string $after = null): self
    {
        if ((null === $before) === (null === $after)) {
            throw new \InvalidArgumentException('withStep() needs exactly one of before: or after: — placement is the point of it.');
        }

        $selector = (string) ($before ?? $after);
        self::assertSelectorIsWellFormed($selector);

        return new self(
            $this->changes,
            $this->fingerprint,
            [...$this->steps, new PlanStep($selector, null !== $before, $run)],
        );
    }

    /**
     * The steps attached to one change, in attachment order.
     *
     * @return list<PlanStep>
     */
    public function stepsFor(PlannedChange $change, bool $before): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (PlanStep $s): bool => $s->runBefore === $before && $s->matches($change),
        ));
    }

    /**
     * Steps whose selector names no change in this plan — expected on a converged install, and
     * worth recording rather than discarding.
     *
     * @return list<PlanStep>
     */
    public function unmatchedSteps(): array
    {
        $selectors = [];
        foreach ($this->changes as $change) {
            $selectors[PlanStep::selectorFor($change)] = true;
        }

        return array_values(array_filter(
            $this->steps,
            static fn (PlanStep $s): bool => !isset($selectors[$s->selector]),
        ));
    }

    private static function assertSelectorIsWellFormed(string $selector): void
    {
        $kind = strstr($selector, ' ', before_needle: true);
        if (false === $kind || '' === trim(substr($selector, \strlen($kind)))) {
            throw new \InvalidArgumentException(
                sprintf('Step selector "%s" must be "kind table.subject" (or "kind table"), e.g. "add_column orders.payload".', $selector),
            );
        }

        if (!\in_array($kind, PlannedChange::KINDS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Step selector "%s" names no such change kind. One of: %s.',
                $selector,
                implode(', ', PlannedChange::KINDS),
            ));
        }
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
