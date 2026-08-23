<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\DbSession;
use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\DialectSupport;
use Nandan108\AttrecordMigrations\MigrationFailedException;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\RefTargetRecord;

/**
 * Change-attached steps against a real database, where the only claim that matters can be checked:
 * that a step runs at its **position** in the apply order, not merely somewhere in the run.
 *
 * The two orderings are tested from opposite sides of the same `add_column`: the `after:` step
 * writes to the column (impossible a moment earlier), and the `before:` step asks the catalogue
 * whether it exists yet (it must not). Position is therefore proven by the database's own state
 * rather than by a flag the test itself set.
 *
 * @phpstan-require-extends \Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase|\Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase
 */
trait PlanStepCases
{
    /** @var list<class-string<Record>> */
    private static array $stepClasses = [RefTargetRecord::class, KitchenSinkRecord::class];

    private function stepMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    /** Converge everything, then drop one column so the next plan is a single `add_column`. */
    private function planWithOneAddColumn(SchemaMigrator $migrator): \Nandan108\AttrecordMigrations\Plan\Plan
    {
        $migrator->apply($migrator->plan(self::$stepClasses));
        static::$session->exec('ALTER TABLE '.$this->q('mig_kitchen_sink').' DROP COLUMN '.$this->q('delta'));

        $plan = $migrator->plan(self::$stepClasses);
        self::assertSame(['add_column'], array_map(static fn ($c): string => $c->kind, $plan->changes));

        return $plan;
    }

    /** Identifier quoting for the raw DDL these cases inject; overridden per backend if needed. */
    protected function q(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    public function testAStepRunsAfterItsChange(): void
    {
        // The backfill case: the column has to exist before anything can write to it, so a step
        // that runs at the wrong end throws instead of quietly doing nothing.
        $migrator = $this->stepMigrator();
        $plan = $this->planWithOneAddColumn($migrator);

        $ran = false;
        $migrator->apply($plan->withStep(
            after: 'add_column mig_kitchen_sink.delta',
            run: function (DbSession $session) use (&$ran): void {
                $session->exec('UPDATE '.$this->q('mig_kitchen_sink').' SET '.$this->q('delta').' = 7');
                $ran = true;
            },
        ));

        self::assertTrue($ran, 'the step ran');
        self::assertTrue($migrator->plan(self::$stepClasses)->isEmpty(), 'and the change still applied');
    }

    public function testAStepRunsBeforeItsChange(): void
    {
        // The mirror: at this point the column must not exist yet.
        $migrator = $this->stepMigrator();
        $plan = $this->planWithOneAddColumn($migrator);

        $columnExistedYet = null;
        $migrator->apply($plan->withStep(
            before: 'add_column mig_kitchen_sink.delta',
            run: function (DbSession $session) use (&$columnExistedYet): void {
                // Asked of the catalogue rather than by watching a SELECT fail: SQLite reads a
                // double-quoted unknown identifier as a *string literal*, so `SELECT "delta"` would
                // cheerfully return the text "delta" and the probe would prove nothing.
                $live = DialectSupport::for(Record::connection()->dialect)
                    ->introspector
                    ->introspectTable($session, 'mig_kitchen_sink');
                $columnExistedYet = isset($live->columns['delta']);
            },
        ));

        self::assertFalse($columnExistedYet, 'a before: step runs while the column is still absent');
        self::assertTrue($migrator->plan(self::$stepClasses)->isEmpty());
    }

    public function testAFailingStepStopsTheRunAndIsReportedAgainstItsChange(): void
    {
        $migrator = $this->stepMigrator();
        $plan = $this->planWithOneAddColumn($migrator);

        try {
            $migrator->apply($plan->withStep(
                before: 'add_column mig_kitchen_sink.delta',
                // A statement, not `fn (): never => throw …`: PHP 8.1 rejects that arrow-function
                // form outright, and 8.1 is this package's floor.
                run: static function (): void {
                    throw new \RuntimeException('the backfill blew up');
                },
            ));
            self::fail('a failing step must stop the run');
        } catch (MigrationFailedException $e) {
            self::assertSame('add_column', $e->change->kind, 'reported against the change it was attached to');
            self::assertStringContainsString('the backfill blew up', $e->getMessage());
        }

        // The change it guarded did not run: that is the point of attaching the step before it.
        self::assertFalse($migrator->plan(self::$stepClasses)->isEmpty());
    }

    public function testAStepWhoseChangeIsNotPlannedSimplyDoesNotRun(): void
    {
        // The converged-install case, which must not be an error: the column was added on an
        // earlier boot, so the plan is empty while the consumer still attaches its step.
        $migrator = $this->stepMigrator();
        $migrator->apply($migrator->plan(self::$stepClasses));

        $ran = false;
        $plan = $migrator->plan(self::$stepClasses);
        self::assertTrue($plan->isEmpty());

        $run = $migrator->apply($plan->withStep(
            after: 'add_column mig_kitchen_sink.delta',
            run: static function () use (&$ran): void { $ran = true; },
        ));

        self::assertFalse($ran);
        // Recorded rather than discarded, so "my backfill never ran" is answerable afterwards.
        $unmatched = array_values(array_filter(
            $run->statements_json,
            static fn (array $o): bool => (bool) ($o['unmatched'] ?? false),
        ));
        self::assertCount(1, $unmatched);
        self::assertSame('add_column mig_kitchen_sink.delta', $unmatched[0]['subject'] ?? null);
    }

    public function testTheStepIsRecordedInTheRunLedger(): void
    {
        $migrator = $this->stepMigrator();
        $plan = $this->planWithOneAddColumn($migrator);

        $run = $migrator->apply($plan->withStep(
            after: 'add_column mig_kitchen_sink.delta',
            run: static function (DbSession $session): void {
                $session->inTransaction();
            },
        ));

        $labels = array_map(static fn (array $o): string => (string) ($o['sql'] ?? ''), $run->statements_json);
        self::assertContains('after add_column mig_kitchen_sink.delta', $labels);
    }
}
