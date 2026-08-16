<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\Plan;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    /** @param list<string> $statements */
    private static function change(ChangeClass $class, string $kind = 'add_column', array $statements = ['SQL']): PlannedChange
    {
        return new PlannedChange('t', $kind, 'c', $class, $statements, 'reason');
    }

    public function testEmptyPlan(): void
    {
        $plan = new Plan([]);
        self::assertTrue($plan->isEmpty());
        self::assertFalse($plan->hasBeyondSafe());
        self::assertSame([], $plan->statements());
    }

    public function testClassBucketsAndStatements(): void
    {
        $plan = new Plan([
            self::change(ChangeClass::Safe, statements: ['A', 'B']),
            self::change(ChangeClass::Destructive, 'drop_column', ['C']),
            self::change(ChangeClass::Manual, 'manual', []),
        ]);

        self::assertFalse($plan->isEmpty());
        self::assertTrue($plan->hasDestructive());
        self::assertTrue($plan->hasManual());
        self::assertTrue($plan->hasBeyondSafe());
        self::assertCount(1, $plan->byClass(ChangeClass::Safe));
        // Manual contributes no SQL — a plan's statements are exactly what apply() could run.
        self::assertSame(['A', 'B', 'C'], $plan->statements());
    }

    public function testCeilingSemantics(): void
    {
        // The ladder: Safe → Destructive → Assisted, each ceiling admitting everything at or below.
        self::assertTrue(ChangeClass::Safe->withinCeiling(ChangeClass::Safe));
        self::assertTrue(ChangeClass::Safe->withinCeiling(ChangeClass::Destructive));
        self::assertTrue(ChangeClass::Safe->withinCeiling(ChangeClass::Assisted));
        self::assertFalse(ChangeClass::Destructive->withinCeiling(ChangeClass::Safe));
        self::assertTrue(ChangeClass::Destructive->withinCeiling(ChangeClass::Destructive));
        self::assertTrue(ChangeClass::Destructive->withinCeiling(ChangeClass::Assisted));
    }

    public function testAssistedNeedsItsOwnCeiling(): void
    {
        // Assisted is not reached by opting into Destructive: the point of the class is that
        // someone chose it specifically, so widening the destructive policy must not sweep it in.
        self::assertFalse(ChangeClass::Assisted->withinCeiling(ChangeClass::Safe));
        self::assertFalse(ChangeClass::Assisted->withinCeiling(ChangeClass::Destructive));
        self::assertTrue(ChangeClass::Assisted->withinCeiling(ChangeClass::Assisted));
    }

    public function testManualNeverRunsUnderAnyCeiling(): void
    {
        // Manual is off the ladder rather than at the top of it: it carries no statements, so
        // there is nothing a higher ceiling could authorise.
        foreach (ChangeClass::cases() as $ceiling) {
            self::assertFalse(ChangeClass::Manual->withinCeiling($ceiling), "ceiling {$ceiling->value}");
        }
    }
}
