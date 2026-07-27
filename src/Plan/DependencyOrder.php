<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Plan;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;

/**
 * Orders table schemas so a foreign key's target table is always created before the table that
 * references it — derived from the declared `#[ForeignKey]` graph, never from a hand-maintained
 * list.
 *
 * Callers used to owe `plan()` a correctly ordered class list, which is a footgun: the information
 * is already in the attributes, the failure only shows up against an empty database (a fresh
 * install, the one path least often exercised), and every consumer would have to rediscover it.
 *
 * Ordering is keyed by **table name**, not by class, because a schema need not come from a class
 * at all — {@see TableSchema::extendedWith()} produces one describing columns no Record could
 * declare. The table is the thing being created; the class is just one way to describe it.
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
     * @param list<TableSchema> $schemas
     */
    public static function resolve(array $schemas): DependencyResolution
    {
        $byTable = [];
        foreach ($schemas as $schema) {
            $byTable[$schema->tableName] = $schema;
        }

        /** @var array<string, list<array{target: string, constraint: string}>> $dependencies */
        $dependencies = [];
        foreach ($schemas as $schema) {
            $table = $schema->tableName;
            $dependencies[$table] = [];
            foreach ($schema->foreignKeys as $fk) {
                $target = $fk->targetTableName();
                if (!isset($byTable[$target]) || $target === $table) {
                    continue; // unmanaged target, or a self-reference
                }
                $dependencies[$table][] = ['target' => $target, 'constraint' => $fk->constraintName];
            }
        }

        $sorted = [];
        $deferred = [];
        $state = []; // table => 1 visiting, 2 done
        foreach ($schemas as $schema) {
            self::visit($schema->tableName, $dependencies, $state, $sorted, $deferred);
        }

        return new DependencyResolution(
            array_map(static fn (string $t): TableSchema => $byTable[$t], $sorted),
            $deferred,
        );
    }

    /**
     * Creation order for a set of Record classes — the common case, where every table is described
     * by a class.
     *
     * @param list<class-string<Record>> $recordClasses
     *
     * @return list<class-string<Record>>
     */
    public static function sort(array $recordClasses): array
    {
        $byTable = [];
        foreach ($recordClasses as $class) {
            $byTable[TableSchema::fromClass($class)->tableName] = $class;
        }

        $schemas = array_map(static fn (string $c): TableSchema => TableSchema::fromClass($c), $recordClasses);

        return array_map(
            static fn (TableSchema $s): string => $byTable[$s->tableName],
            self::resolve($schemas)->schemas,
        );
    }

    /**
     * Depth-first emit: a table is appended only once everything it depends on has been.
     *
     * An edge pointing at a table still being visited is a **back-edge** — the loop closes here.
     * That edge's constraint is recorded as deferred and the recursion stops, which is what makes
     * the remaining graph acyclic and therefore orderable. Deferring the edge on *this* table (the
     * one closing the loop) rather than on the ancestor keeps the choice deterministic: it falls
     * out of the input order, so the same model set always defers the same constraint.
     *
     * @param array<string, list<array{target: string, constraint: string}>> $dependencies
     * @param array<string, int>                                             $state
     * @param list<string>                                                   $sorted
     * @param array<string, list<string>>                                    $deferred
     *
     * @param-out array<string, int>          $state
     * @param-out list<string>                $sorted
     * @param-out array<string, list<string>> $deferred
     */
    private static function visit(string $table, array $dependencies, array &$state, array &$sorted, array &$deferred): void
    {
        if (2 === ($state[$table] ?? 0)) {
            return;
        }

        $state[$table] = 1;
        foreach ($dependencies[$table] ?? [] as $edge) {
            if (1 === ($state[$edge['target']] ?? 0)) {
                $deferred[$table][] = $edge['constraint'];
                continue;
            }
            self::visit($edge['target'], $dependencies, $state, $sorted, $deferred);
        }
        $state[$table] = 2;
        $sorted[] = $table;
    }
}
