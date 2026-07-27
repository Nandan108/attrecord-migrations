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
        // Safe runs under any ceiling; Destructive only under the Destructive ceiling;
        // Manual never runs, whatever the ceiling.
        self::assertTrue(ChangeClass::Safe->withinCeiling(ChangeClass::Safe));
        self::assertTrue(ChangeClass::Safe->withinCeiling(ChangeClass::Destructive));
        self::assertFalse(ChangeClass::Destructive->withinCeiling(ChangeClass::Safe));
        self::assertTrue(ChangeClass::Destructive->withinCeiling(ChangeClass::Destructive));
        self::assertFalse(ChangeClass::Manual->withinCeiling(ChangeClass::Destructive));
        self::assertFalse(ChangeClass::Manual->withinCeiling(ChangeClass::Manual));
    }
}
