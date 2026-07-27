<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

use Nandan108\Attrecord\Record;

/**
 * The creation order for a model set, plus the foreign keys that order alone cannot satisfy.
 *
 * A cycle (`a` references `b` references `a`) has no valid order while every FK is emitted inline
 * in `CREATE TABLE`. Rather than refuse the model, one edge of each cycle is **deferred**: its
 * table is created without that constraint, and the constraint is added once both tables exist.
 *
 * @see DependencyOrder::resolve()
 */
final class DependencyResolution
{
    /**
     * @param list<class-string<Record>>                $classes  creation order — every class after the ones it references, ignoring deferred edges
     * @param array<class-string<Record>, list<string>> $deferred constraint names to leave out of each class's CREATE and add afterwards
     */
    public function __construct(
        public readonly array $classes,
        public readonly array $deferred = [],
    ) {
    }

    /**
     * Constraint names to omit from this class's `CREATE TABLE`.
     *
     * @param class-string<Record> $class
     *
     * @return list<string>
     */
    public function deferredFor(string $class): array
    {
        return $this->deferred[$class] ?? [];
    }

    /** Whether any foreign key had to be deferred — i.e. the model set contains a cycle. */
    public function hasDeferred(): bool
    {
        return [] !== $this->deferred;
    }
}
