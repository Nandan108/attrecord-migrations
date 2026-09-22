<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\CompositeFkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\CompositePkRecord;

/**
 * A table keyed on two columns, converged against a real database.
 *
 * Such a table could not be declared at all before attrecord 0.13, so its DDL was hand-written —
 * and hand-written DDL is invisible here, because the differ compares the live database against
 * *declared* schemas. It sat outside the managed set and drifted unobserved. Now that it can be
 * declared, the property that matters is the usual one: **a converged database re-plans empty.**
 *
 * That is not a formality for this shape. The differ compared the live key against `[$desired->pk]`
 * — a single-element list — so a composite table matched its own live definition on no engine, and
 * reported `Manual` drift forever, starting the instant it was created. A test that only checked
 * "the table exists" would have passed throughout.
 *
 * Every engine reports a composite PK in key order, so unlike affinity or member lists there is
 * nothing here that SQLite cannot see — all three backends run the same expectations.
 */
trait CompositePkCases
{
    /** Reading the key back is the only way to prove it landed in the declared order. */
    abstract protected function introspector(): SchemaIntrospector;

    /** @var list<class-string<Record>> */
    private static array $compositePkClasses = [CompositePkRecord::class];

    private function compositePkMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    public function testCompositePkTableConvergesAndReplansEmpty(): void
    {
        $migrator = $this->compositePkMigrator();

        $plan = $migrator->plan(self::$compositePkClasses);
        self::assertSame(
            ['create_table'],
            array_map(static fn ($c): string => $c->kind, $plan->changes),
        );

        $run = $migrator->apply($plan);
        self::assertNull($run->error);

        $replan = $migrator->plan(self::$compositePkClasses);
        self::assertTrue($replan->isEmpty(), 'a composite-PK table must re-plan empty; got: '.implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->table}.{$c->subject}: {$c->reason})",
            $replan->changes,
        )));
    }

    /** The declared order is the physical index order, so it has to survive the round trip. */
    public function testTheKeyRoundTripsInDeclaredOrder(): void
    {
        $migrator = $this->compositePkMigrator();
        $migrator->apply($migrator->plan(self::$compositePkClasses));

        $table = TableSchema::fromClass(CompositePkRecord::class)->tableName;
        $live = $this->introspector()->introspectTable(Record::connection()->session, $table);

        self::assertNotNull($live);
        self::assertSame(['owner_id', 'item_id'], $live->primaryKey);
    }

    /**
     * A *changed* key is still never auto-applied. Rebuilding a primary key rewrites the clustered
     * index and can fail outright on duplicate rows, so it is reported for a human — the guarantee
     * being that composite keys became *comparable*, not that they became alterable.
     */
    public function testAChangedKeyIsStillManual(): void
    {
        $migrator = $this->compositePkMigrator();
        $migrator->apply($migrator->plan(self::$compositePkClasses));

        $table = TableSchema::fromClass(CompositePkRecord::class)->tableName;
        $q = Record::connection()->dialect->quoteIdentifier(...);
        foreach ($this->dropAndNarrowPrimaryKeySql($q($table), $q('owner_id')) as $sql) {
            static::$session->exec($sql);
        }

        $plan = $migrator->plan(self::$compositePkClasses);
        $manual = array_values(array_filter(
            $plan->changes,
            static fn ($c): bool => ChangeClass::Manual === $c->class,
        ));

        self::assertNotSame([], $manual, 'a narrowed key must be reported');
        self::assertStringContainsString('primary key differs', $manual[0]->reason);
        self::assertSame([], $manual[0]->statements, 'Manual carries a reason, never SQL');
    }

    /**
     * A foreign key over the **whole** two-column key converges and re-plans empty.
     *
     * The discriminating test for the multi-column FK support, and it is discriminating for a
     * precise reason: the differ compares a desired key against the live one as a *shape* — local
     * columns, target, referenced columns, actions. Before attrecord 0.23 the desired side could
     * only be one column, so `desiredFkShape()` wrapped a scalar. A two-column live key compared
     * against a one-element desired list matches on no engine, so the live key reads as undeclared
     * and the declared one as missing: a drop and re-add proposed on every plan, forever, starting
     * the instant the table is created.
     *
     * A test that only asserted "the child table exists" would pass throughout — which is the same
     * trap `testCompositePkTableConvergesAndReplansEmpty` exists for, one level out.
     */
    public function testACompositeForeignKeyConvergesAndReplansEmpty(): void
    {
        $migrator = $this->compositePkMigrator();
        $classes = [CompositePkRecord::class, CompositeFkRecord::class];

        $plan = $migrator->plan($classes);
        $run = $migrator->apply($plan);
        self::assertNull($run->error);

        $replan = $migrator->plan($classes);
        self::assertTrue($replan->isEmpty(), 'a composite-FK table must re-plan empty; got: '.implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->table}.{$c->subject}: {$c->reason})",
            $replan->changes,
        )));
    }

    /**
     * The pairs survive the round trip, in order and paired with the right members.
     *
     * Re-planning empty proves the two shapes agree; it does not prove they agree about the *right*
     * thing, since two identically wrong shapes also compare equal. This reads the pairing back out
     * of the catalogue instead.
     */
    public function testTheForeignKeyPairsRoundTripInDeclaredOrder(): void
    {
        $migrator = $this->compositePkMigrator();
        $migrator->apply($migrator->plan([CompositePkRecord::class, CompositeFkRecord::class]));

        $childTable = TableSchema::fromClass(CompositeFkRecord::class)->tableName;
        $live = $this->introspector()->introspectTable(Record::connection()->session, $childTable);

        self::assertNotNull($live);
        self::assertCount(1, $live->foreignKeys, 'two columns are one constraint, not two');

        $fk = array_values($live->foreignKeys)[0];
        self::assertSame(['parent_owner_id', 'parent_item_id'], $fk->localColumns);
        self::assertSame(['owner_id', 'item_id'], $fk->referencedColumns, 'paired with the local side, in key order');
        self::assertSame(TableSchema::fromClass(CompositePkRecord::class)->tableName, $fk->referencedTable);
    }

    /**
     * The name attrecord derives for a multi-column key is the name the engine reports back.
     *
     * Nobody can check this by reading: the differ keys foreign keys by name, so a derivation that
     * disagrees with the catalogue presents as one key missing and another undeclared — which is
     * the same symptom as a shape mismatch and has a different cause.
     */
    public function testTheDerivedConstraintNameIsWhatTheEngineReports(): void
    {
        $migrator = $this->compositePkMigrator();
        $migrator->apply($migrator->plan([CompositePkRecord::class, CompositeFkRecord::class]));

        $declared = TableSchema::fromClass(CompositeFkRecord::class);
        $derivedName = $declared->foreignKeys[0]->constraintName;

        $live = $this->introspector()->introspectTable(Record::connection()->session, $declared->tableName);
        self::assertNotNull($live);
        self::assertArrayHasKey($derivedName, $live->foreignKeys, sprintf(
            'attrecord derived "%s"; the catalogue reports [%s]',
            $derivedName,
            implode(', ', array_map(strval(...), array_keys($live->foreignKeys))),
        ));
    }

    /**
     * Narrowing the key to its first column — the drift the old code could not distinguish from a
     * correctly-created table, since it compared against exactly that single column.
     *
     * @return list<string>
     */
    abstract protected function dropAndNarrowPrimaryKeySql(string $quotedTable, string $quotedFirstColumn): array;
}
