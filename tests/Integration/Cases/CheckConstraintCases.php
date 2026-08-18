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
 * SQLite can do neither ADD nor DROP CONSTRAINT, so its runner overrides the two convergence
 * expectations to Manual — pinned rather than skipped, the way the drift matrix pins per-backend
 * blind spots.
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
        if (!$this->altersConstraintsInPlace()) {
            self::markTestSkipped('engine cannot add a constraint in place');
        }

        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));
        $this->removeCheckFromLiveTable();

        $violating = new CheckedRecord();
        $violating->archived = 1;
        $violating->reason = null;
        $violating->save();

        $plan = $migrator->plan([CheckedRecord::class]);

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
        if (!$this->altersConstraintsInPlace()) {
            self::markTestSkipped('engine cannot drop a constraint in place');
        }

        $migrator = $this->checkMigrator();
        $migrator->apply($migrator->plan([CheckedRecord::class]));
        static::$session->exec($this->addCheckSql('chk_hand_written', 'archived <> 9'));

        $plan = $migrator->plan([CheckedRecord::class]);
        $drop = $plan->changes[0];

        self::assertSame('drop_check', $drop->kind);
        self::assertSame(ChangeClass::Destructive, $drop->class);

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

    /** Raw DDL to add a constraint nobody declared. */
    protected function addCheckSql(string $name, string $expression): string
    {
        return "ALTER TABLE mig_checked ADD CONSTRAINT {$name} CHECK ({$expression})";
    }
}
