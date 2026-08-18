<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\MigrationFailedException;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\CheckedRecord;

/**
 * CHECK-constraint convergence against a real engine, which is the only place several of these
 * claims can be tested at all: whether a constraint *round-trips* (the golden invariant — a table
 * created from its Record must re-plan empty, and an engine that re-spells the expression is what
 * makes that non-trivial), and whether `ADD CONSTRAINT` really validates the rows already there.
 *
 * SQLite can do neither ADD nor DROP CONSTRAINT, so every convergence case there asserts Manual
 * instead — pinned rather than skipped, the way the drift matrix pins per-backend blind spots, and
 * because CI runs `--fail-on-skipped` on the principle that a skipped backend is not a passing one.
 */
trait CheckConstraintCases
{
    private function checkMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    /** Whether this engine can add and drop a constraint in place (everything except SQLite). */
    protected function altersConstraintsInPlace(): bool
    {
        return true;
    }

    private static function checkName(): string
    {
        return (string) array_key_first(TableSchema::fromClass(CheckedRecord::class)->checks);
    }

    public function testATableCreatedWithItsCheckRePlansEmpty(): void
    {
        // The golden invariant, for constraints: the engine stores its own spelling of the
        // expression, so anything comparing bodies would report drift on a table that is exactly
        // as declared. Name-only diffing is what makes this pass.
        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));

        $replan = $migrator->plan([CheckedRecord::class]);
        self::assertTrue($replan->isEmpty(), 'converged: '.implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->subject}: {$c->reason})",
            $replan->changes,
        )));
    }

    public function testAddingACheckToAnExistingTableConverges(): void
    {
        // The upgrade path: the table predates the rule. This is where a WordPress-style consumer
        // actually lives — a fresh install is the easy half.
        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));
        $this->removeCheckFromLiveTable();

        $plan = $migrator->plan([CheckedRecord::class]);

        if (!$this->altersConstraintsInPlace()) {
            self::assertNotEmpty($plan->byClass(ChangeClass::Manual), 'engine cannot ALTER a constraint: Manual');

            return;
        }

        $add = $plan->changes[0];
        self::assertSame('add_check', $add->kind);
        self::assertSame(ChangeClass::Safe, $add->class);
        self::assertTrue($add->mayRejectExistingRows);

        $migrator->apply($plan);
        self::assertTrue($migrator->plan([CheckedRecord::class])->isEmpty());
    }

    public function testTheConstraintIsEnforcedOnceApplied(): void
    {
        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));

        $ok = new CheckedRecord();
        $ok->archived = 1;
        $ok->reason = 'superseded';
        $ok->save();
        self::assertNotNull($ok->id);

        $bad = new CheckedRecord();
        $bad->archived = 1;
        $bad->reason = null;

        $this->expectException(\Throwable::class);
        $bad->save();
    }

    public function testAddingACheckToViolatingDataFailsLoudlyAndChangesNothing(): void
    {
        // The reason `add_check` is flagged `mayRejectExistingRows`: the statement validates every
        // existing row. It must fail atomically — the constraint absent afterwards, the rows
        // untouched — never half-applied, and never silently dropping the offending rows.
        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));
        $this->removeCheckFromLiveTable();

        $violating = new CheckedRecord();
        $violating->archived = 1;
        $violating->reason = null;
        $violating->save();

        $plan = $migrator->plan([CheckedRecord::class]);

        if (!$this->altersConstraintsInPlace()) {
            // Nothing to attempt: the change is Manual and carries no statement, so applying the
            // plan is a no-op and the rows are safe by a different route — inability, not restraint.
            $migrator->apply($plan, ChangeClass::Destructive);
            self::assertSame(1, CheckedRecord::countWhere('archived = ?', [1]));

            return;
        }

        try {
            $migrator->apply($plan);
            self::fail('ADD CONSTRAINT over violating rows must fail');
        } catch (MigrationFailedException $e) {
            self::assertSame('add_check', $e->change->kind);
        }

        self::assertSame(1, CheckedRecord::countWhere('archived = ?', [1]), 'the offending row is still there');
        self::assertFalse($migrator->plan([CheckedRecord::class])->isEmpty(), 'the constraint was not added');
    }

    public function testAnUndeclaredCheckIsProposedForDroppingOnlyBeyondSafe(): void
    {
        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));
        $this->addUndeclaredCheckToLiveTable();

        $plan = $migrator->plan([CheckedRecord::class]);
        $change = $plan->changes[0];

        if (!$this->altersConstraintsInPlace()) {
            self::assertSame(ChangeClass::Manual, $change->class);
            self::assertSame([], $change->statements, 'Manual carries no SQL to run');

            return;
        }

        self::assertSame('drop_check', $change->kind);
        self::assertSame(ChangeClass::Destructive, $change->class);

        // Safe alone leaves it: dropping something the database already enforces is a decision.
        $migrator->apply($plan);
        self::assertFalse($migrator->plan([CheckedRecord::class])->isEmpty());

        $migrator->apply($plan, ChangeClass::Destructive);
        self::assertTrue($migrator->plan([CheckedRecord::class])->isEmpty());
    }

    /**
     * Take the constraint off the live table behind the migrator's back, to stand in for an install
     * that predates the rule. Overridden where the engine has no DROP CONSTRAINT.
     */
    protected function removeCheckFromLiveTable(): void
    {
        static::$session->exec('ALTER TABLE mig_checked DROP CONSTRAINT '.self::checkName());
    }

    /** Put a constraint nobody declared on the live table. Overridden where there is no ADD. */
    protected function addUndeclaredCheckToLiveTable(): void
    {
        static::$session->exec('ALTER TABLE mig_checked ADD CONSTRAINT chk_hand_written CHECK (archived <> 9)');
    }
}
