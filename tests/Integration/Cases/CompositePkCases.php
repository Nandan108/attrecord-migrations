<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
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
        self::assertSame(['subject_id', 'slot_id'], $live->primaryKey);
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
        foreach ($this->dropAndNarrowPrimaryKeySql($q($table), $q('subject_id')) as $sql) {
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
     * Narrowing the key to its first column — the drift the old code could not distinguish from a
     * correctly-created table, since it compared against exactly that single column.
     *
     * @return list<string>
     */
    abstract protected function dropAndNarrowPrimaryKeySql(string $quotedTable, string $quotedFirstColumn): array;
}
