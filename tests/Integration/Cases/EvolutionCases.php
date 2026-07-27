<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\RefTargetRecord;

/**
 * End-to-end converge lifecycle on a real database, identical across backends:
 * fresh install → empty plan (idempotence) → drift injected via raw DDL → planned, classified,
 * applied within the right ceiling → empty plan again. Plus the run ledger, the run-once data
 * step, and fingerprint stability.
 */
trait EvolutionCases
{
    private function migrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    /** @var list<class-string<Record>> */
    private static array $classes = [RefTargetRecord::class, KitchenSinkRecord::class];

    public function testConvergeFromScratchThenIdempotent(): void
    {
        $migrator = $this->migrator();

        $plan = $migrator->plan(self::$classes);
        self::assertCount(2, $plan->byClass(ChangeClass::Safe));
        self::assertFalse($plan->hasBeyondSafe());

        $run = $migrator->apply($plan);
        self::assertNull($run->error);
        self::assertNotNull($run->id, 'run is recorded in the ledger');

        $replan = $migrator->plan(self::$classes);
        self::assertTrue($replan->isEmpty(), 'converged database must re-plan empty; got: '.implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->table}.{$c->subject}: {$c->reason})",
            $replan->changes,
        )));

        self::assertSame($plan->fingerprint, $replan->fingerprint, 'fingerprint is a pure function of the model set');
    }

    public function testMissingColumnIsPlannedSafeAndConverges(): void
    {
        $migrator = $this->migrator();
        $migrator->apply($migrator->plan(self::$classes));

        // Simulate an older install: the nullable, unindexed `delta` column does not exist yet.
        static::$session->exec('ALTER TABLE mig_kitchen_sink DROP COLUMN delta');

        $plan = $migrator->plan(self::$classes);
        self::assertCount(1, $plan->changes);
        self::assertSame('add_column', $plan->changes[0]->kind);
        self::assertSame(ChangeClass::Safe, $plan->changes[0]->class);

        $migrator->apply($plan);
        self::assertTrue($migrator->plan(self::$classes)->isEmpty());
    }

    public function testUndeclaredColumnNeedsDestructiveOptIn(): void
    {
        $migrator = $this->migrator();
        $migrator->apply($migrator->plan(self::$classes));

        static::$session->exec('ALTER TABLE mig_kitchen_sink ADD COLUMN legacy_note TEXT');

        $plan = $migrator->plan(self::$classes);
        self::assertCount(1, $plan->changes);
        self::assertSame('drop_column', $plan->changes[0]->kind);
        self::assertSame(ChangeClass::Destructive, $plan->changes[0]->class);

        // Default ceiling: the destructive drop is skipped, not executed and not an error.
        $migrator->apply($plan);
        self::assertFalse($migrator->plan(self::$classes)->isEmpty(), 'column must survive a Safe-ceiling apply');

        // Explicit opt-in: it runs.
        $migrator->apply($plan, allow: ChangeClass::Destructive);
        self::assertTrue($migrator->plan(self::$classes)->isEmpty());
    }

    public function testDataStepRunsExactlyOnce(): void
    {
        $migrator = $this->migrator();
        $migrator->apply($migrator->plan(self::$classes));

        // The step's effect is counted through the database — which doubles as proof the closure
        // receives a working session.
        $step = static function (\Nandan108\Attrecord\DbSession $session): void {
            $session->exec('INSERT INTO mig_ref_targets (code) VALUES (?)', ['step-ran']);
        };
        $ran = fn (): int => (int) static::$session->fetchScalar('SELECT COUNT(*) FROM mig_ref_targets WHERE code = ?', ['step-ran']);

        self::assertTrue($migrator->dataStep('2026-07-test-step', $step));
        self::assertFalse($migrator->dataStep('2026-07-test-step', $step));
        self::assertSame(1, $ran());

        // A different key runs independently.
        self::assertTrue($migrator->dataStep('2026-07-other-step', $step));
        self::assertSame(2, $ran());
    }

    public function testFailedStatementIsRecordedAndRethrown(): void
    {
        $migrator = $this->migrator();
        $migrator->apply($migrator->plan(self::$classes));

        // Inject a plan whose single change fails (duplicate column) — the run must be recorded
        // with the error and the failure rethrown as MigrationFailedException.
        $bad = new \Nandan108\AttrecordMigrations\Plan\Plan([
            new \Nandan108\AttrecordMigrations\Plan\PlannedChange(
                'mig_kitchen_sink',
                'add_column',
                'sku',
                ChangeClass::Safe,
                ['ALTER TABLE mig_kitchen_sink ADD COLUMN sku VARCHAR(64)'],
                'test: duplicate add',
            ),
        ], 'test-fingerprint');

        try {
            $migrator->apply($bad);
            self::fail('expected MigrationFailedException');
        } catch (\Nandan108\AttrecordMigrations\MigrationFailedException $e) {
            self::assertSame('sku', $e->change->subject);
        }

        $lastRun = Record::usingConnection(
            Record::connection(),
            static fn () => \Nandan108\AttrecordMigrations\Ledger\SchemaRunRecord::findOne('error IS NOT NULL', orderByLimit: 'ORDER BY id DESC LIMIT 1'),
        );
        self::assertNotNull($lastRun, 'failed run is recorded');
        self::assertStringContainsString('sku', (string) $lastRun->error);
    }
}
