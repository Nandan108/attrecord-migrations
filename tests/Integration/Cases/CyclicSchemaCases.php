<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\CycleLeftRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\CycleRightRecord;

/**
 * Two tables that reference each other, converged against a real database.
 *
 * No creation order satisfies a loop while every FK is inline, so one constraint is deferred out
 * of its `CREATE TABLE` and added once both tables exist. The property that matters is the usual
 * one — **a converged database re-plans empty** — but reaching it here requires the deferral to be
 * both emitted and ordered correctly, which only a real engine can confirm: it is the engine, not
 * the pipeline, that rejects an FK pointing at a table that does not exist yet.
 *
 * SQLite is the documented exception: it cannot add a foreign key to an existing table, so the
 * deferred constraint classifies `Manual` and is reported rather than applied.
 */
trait CyclicSchemaCases
{
    /** Reading the constraints back is the only way to prove the deferred FK really landed. */
    abstract protected function introspector(): SchemaIntrospector;

    /** @var list<class-string<Record>> */
    private static array $cycleClasses = [CycleLeftRecord::class, CycleRightRecord::class];

    private function cycleMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    private function engineCanAddForeignKeys(): bool
    {
        return !Record::connection()->dialect instanceof SqliteDialect;
    }

    public function testCyclicPairConvergesFromEmpty(): void
    {
        $migrator = $this->cycleMigrator();
        $plan = $migrator->plan(self::$cycleClasses);

        $kinds = array_map(static fn ($c): string => $c->kind, $plan->changes);
        self::assertSame(['create_table', 'create_table'], \array_slice($kinds, 0, 2), 'both tables are created first');

        if (!$this->engineCanAddForeignKeys()) {
            // SQLite: the deferred constraint has nowhere to go — reported, never guessed at.
            self::assertSame(['create_table', 'create_table', 'manual'], $kinds);
            self::assertSame(ChangeClass::Manual, $plan->changes[2]->class);
            self::assertSame([], $plan->changes[2]->statements);

            $migrator->apply($plan);
            $replan = $migrator->plan(self::$cycleClasses);
            self::assertCount(1, $replan->changes, 'tables converge; only the un-addable FK remains');
            self::assertSame(ChangeClass::Manual, $replan->changes[0]->class);

            return;
        }

        self::assertSame(['create_table', 'create_table', 'add_foreign_key'], $kinds, 'the deferred FK trails both creates');

        $run = $migrator->apply($plan);
        self::assertNull($run->error);

        $replan = $migrator->plan(self::$cycleClasses);
        self::assertTrue($replan->isEmpty(), 'a cyclic schema must still re-plan empty; got: '.implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->table}.{$c->subject}: {$c->reason})",
            $replan->changes,
        )));
    }

    public function testBothForeignKeysExistAfterConverging(): void
    {
        if (!$this->engineCanAddForeignKeys()) {
            self::assertTrue(true, 'SQLite cannot add the deferred FK — covered by the convergence case');

            return;
        }

        $migrator = $this->cycleMigrator();
        $migrator->apply($migrator->plan(self::$cycleClasses));

        // Read the constraints back from the engine: the deferred one must be a real FK, not
        // merely absent from the plan.
        foreach (['mig_cycle_left', 'mig_cycle_right'] as $table) {
            $live = $this->introspector()->introspectTable(static::$session, $table);
            self::assertNotNull($live);
            self::assertCount(1, $live->foreignKeys, "{$table} must carry its declared foreign key");
        }
    }

    public function testDeferralIsIdempotentAcrossRepeatedApplies(): void
    {
        $migrator = $this->cycleMigrator();
        $migrator->apply($migrator->plan(self::$cycleClasses));
        $migrator->apply($migrator->plan(self::$cycleClasses));

        $replan = $migrator->plan(self::$cycleClasses);
        $expected = $this->engineCanAddForeignKeys() ? 0 : 1; // SQLite keeps reporting the Manual FK
        self::assertCount($expected, $replan->changes);
    }
}
