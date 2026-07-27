<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * Orders Record classes so a foreign key's target table is always created before the table that
 * references it — derived from the declared `#[ForeignKey]` graph, never from a hand-maintained
 * list.
 *
 * Callers used to owe `plan()` a correctly ordered class list, which is a footgun: the information
 * is already in the attributes, the failure only shows up against an empty database (a fresh
 * install, the one path least often exercised), and every consumer would have to rediscover it.
 *
 * Two edges are deliberately *not* dependencies:
 * - **Self-references** (a tree's `parent_id`), which a table can satisfy within its own CREATE.
 * - **Targets outside the given set** — a table this model set does not manage (created by other
 *   means, or simply not being converged). It is treated as pre-existing rather than as a missing
 *   dependency, matching the pipeline's rule that unmanaged objects are invisible, not errors.
 *
 * A **cycle** has no valid order at all while FKs are emitted inline, so one edge of it is
 * deferred instead: see {@see DependencyResolution}.
 */
final class DependencyOrder
{
    /**
     * Creation order plus the foreign keys that had to be deferred to break cycles.
     *
     * Input order is otherwise preserved, so an unconstrained set comes back untouched (a stable
     * result keeps plans and fingerprints from churning when nothing structural changed).
     *
     * @param list<class-string<Record>> $recordClasses
     */
    public static function resolve(array $recordClasses): DependencyResolution
    {
        $byTable = [];
        foreach ($recordClasses as $class) {
            $byTable[TableSchema::fromClass($class)->tableName] = $class;
        }

        /** @var array<class-string<Record>, list<array{target: class-string<Record>, constraint: string}>> $dependencies */
        $dependencies = [];
        foreach ($recordClasses as $class) {
            $dependencies[$class] = [];
            foreach (TableSchema::fromClass($class)->foreignKeys as $fk) {
                $target = $byTable[$fk->targetTableName()] ?? null;
                if (null === $target || $target === $class) {
                    continue; // unmanaged target, or a self-reference
                }
                $dependencies[$class][] = ['target' => $target, 'constraint' => $fk->constraintName];
            }
        }

        $sorted = [];
        $deferred = [];
        $state = []; // class => 1 visiting, 2 done
        foreach ($recordClasses as $class) {
            self::visit($class, $dependencies, $state, $sorted, $deferred);
        }

        return new DependencyResolution($sorted, $deferred);
    }

    /**
     * Just the creation order, for callers with no cycles to worry about.
     *
     * @param list<class-string<Record>> $recordClasses
     *
     * @return list<class-string<Record>>
     */
    public static function sort(array $recordClasses): array
    {
        return self::resolve($recordClasses)->classes;
    }

    /**
     * Depth-first emit: a class is appended only once everything it depends on has been.
     *
     * An edge pointing at a class still being visited is a **back-edge** — the loop closes here.
     * That edge's constraint is recorded as deferred and the recursion stops, which is what makes
     * the remaining graph acyclic and therefore orderable. Deferring the edge on *this* class (the
     * one closing the loop) rather than on the ancestor keeps the choice deterministic: it falls
     * out of the input order, so the same model set always defers the same constraint.
     *
     * @param class-string<Record>                                                                       $class
     * @param array<class-string<Record>, list<array{target: class-string<Record>, constraint: string}>> $dependencies
     * @param array<class-string<Record>, int>                                                           $state
     * @param list<class-string<Record>>                                                                 $sorted
     * @param array<class-string<Record>, list<string>>                                                  $deferred
     *
     * @param-out array<class-string<Record>, int>          $state
     * @param-out list<class-string<Record>>                $sorted
     * @param-out array<class-string<Record>, list<string>> $deferred
     */
    private static function visit(string $class, array $dependencies, array &$state, array &$sorted, array &$deferred): void
    {
        if (2 === ($state[$class] ?? 0)) {
            return;
        }

        $state[$class] = 1;
        foreach ($dependencies[$class] ?? [] as $edge) {
            if (1 === ($state[$edge['target']] ?? 0)) {
                $deferred[$class][] = $edge['constraint'];
                continue;
            }
            self::visit($edge['target'], $dependencies, $state, $sorted, $deferred);
        }
        $state[$class] = 2;
        $sorted[] = $class;
    }
}
