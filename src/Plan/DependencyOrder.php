<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\CircularDependencyException;

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
 */
final class DependencyOrder
{
    /**
     * Dependency order: every class appears after all classes it references. Input order is
     * otherwise preserved, so an unconstrained set comes back untouched (a stable sort keeps
     * plans and fingerprints from churning when nothing structural changed).
     *
     * @param list<class-string<Record>> $recordClasses
     *
     * @return list<class-string<Record>>
     *
     * @throws CircularDependencyException when two or more tables reference each other, which no
     *                                     ordering can satisfy while FKs are emitted inline in CREATE TABLE
     */
    public static function sort(array $recordClasses): array
    {
        $byTable = [];
        foreach ($recordClasses as $class) {
            $byTable[TableSchema::fromClass($class)->tableName] = $class;
        }

        /** @var array<class-string<Record>, list<class-string<Record>>> $dependencies */
        $dependencies = [];
        foreach ($recordClasses as $class) {
            $schema = TableSchema::fromClass($class);
            $dependencies[$class] = [];
            foreach ($schema->foreignKeys as $fk) {
                $target = $byTable[$fk->targetTableName()] ?? null;
                if (null === $target || $target === $class) {
                    continue; // unmanaged target, or a self-reference
                }
                $dependencies[$class][] = $target;
            }
        }

        $sorted = [];
        $state = []; // class => 1 visiting, 2 done
        foreach ($recordClasses as $class) {
            self::visit($class, $dependencies, $state, $sorted, []);
        }

        return $sorted;
    }

    /**
     * Depth-first emit: a class is appended only once everything it depends on has been. `$path`
     * carries the current chain so a cycle can be reported as the actual loop rather than as a
     * bare "cycle detected".
     *
     * @param class-string<Record>                                    $class
     * @param array<class-string<Record>, list<class-string<Record>>> $dependencies
     * @param array<class-string<Record>, int>                        $state
     * @param list<class-string<Record>>                              $sorted
     * @param list<class-string<Record>>                              $path
     *
     * @param-out array<class-string<Record>, int> $state
     * @param-out list<class-string<Record>>       $sorted
     */
    private static function visit(string $class, array $dependencies, array &$state, array &$sorted, array $path): void
    {
        if (2 === ($state[$class] ?? 0)) {
            return;
        }
        if (1 === ($state[$class] ?? 0)) {
            // Trim the chain to the loop itself, dropping the repeated entry the exception
            // re-adds when it renders the closing arrow: [A, B, A] reported as [A, B] → "A → B → A".
            $loop = $path;
            $loop[] = $class;
            $from = array_search($class, $loop, true);
            throw new CircularDependencyException(\is_int($from) ? \array_slice($loop, $from, -1) : $loop);
        }

        $state[$class] = 1;
        $path[] = $class;
        foreach ($dependencies[$class] ?? [] as $dependency) {
            self::visit($dependency, $dependencies, $state, $sorted, $path);
        }
        $state[$class] = 2;
        $sorted[] = $class;
    }
}
