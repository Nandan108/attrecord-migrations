<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

use Nandan108\Attrecord\Schema\TableSchema;

/**
 * The creation order for a model set, plus the foreign keys that order alone cannot satisfy.
 *
 * A cycle (`a` references `b` references `a`) has no valid order while every FK is emitted inline
 * in `CREATE TABLE`. Rather than refuse the model, one edge of each cycle is **deferred**: its
 * table is created without that constraint, and the constraint is added once both tables exist.
 *
 * Keyed by table name throughout — a schema need not come from a class (see
 * {@see DependencyOrder}).
 *
 * @see DependencyOrder::resolve()
 */
final class DependencyResolution
{
    /**
     * @param list<TableSchema>           $schemas  creation order — every table after the ones it references, ignoring deferred edges
     * @param array<string, list<string>> $deferred table name → constraint names to leave out of that table's CREATE and add afterwards
     */
    public function __construct(
        public readonly array $schemas,
        public readonly array $deferred = [],
    ) {
    }

    /**
     * Constraint names to omit from this table's `CREATE TABLE`.
     *
     * @return list<string>
     */
    public function deferredFor(string $tableName): array
    {
        return $this->deferred[$tableName] ?? [];
    }

    /** Whether any foreign key had to be deferred — i.e. the model set contains a cycle. */
    public function hasDeferred(): bool
    {
        return [] !== $this->deferred;
    }
}
