<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\Plan;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Attaching a change-attached step: what the selector accepts, what it refuses, and which mistakes
 * it can and cannot catch. Execution against a real database is covered by the integration cases.
 */
final class PlanStepTest extends TestCase
{
    private static function plan(PlannedChange ...$changes): Plan
    {
        return new Plan(array_values($changes));
    }

    private static function change(string $kind = 'add_column', string $table = 'orders', string $subject = 'payload'): PlannedChange
    {
        return new PlannedChange($table, $kind, $subject, ChangeClass::Safe, ['SQL'], 'reason');
    }

    /** @return \Closure(\Nandan108\Attrecord\DbSession): void */
    private static function noop(): \Closure
    {
        // A real step signature — it is handed the session; these cases only care that the step
        // was attached to the right change, so touching it is all the body needs to do.
        return static function (\Nandan108\Attrecord\DbSession $session): void {
            $session->inTransaction();
        };
    }

    #[Test]
    public function aStepAttachesToTheChangeItNames(): void
    {
        $change = self::change();
        $plan = self::plan($change)->withStep(before: 'add_column orders.payload', run: self::noop());

        self::assertCount(1, $plan->stepsFor($change, before: true));
        self::assertSame([], $plan->stepsFor($change, before: false));
        self::assertSame([], $plan->unmatchedSteps());
    }

    #[Test]
    public function placementIsPartOfTheAttachment(): void
    {
        // wrap-before-ALTER and backfill-after-ADD are both real, and each is wrong in the other's
        // place — so a step knows which side it belongs on, and only shows up there.
        $change = self::change();
        $plan = self::plan($change)->withStep(after: 'add_column orders.payload', run: self::noop());

        self::assertSame([], $plan->stepsFor($change, before: true));
        self::assertCount(1, $plan->stepsFor($change, before: false));
    }

    #[Test]
    public function aChangeWithNoSubjectIsNamedByTableAlone(): void
    {
        $create = self::change('create_table', 'orders', '');
        $plan = self::plan($create)->withStep(after: 'create_table orders', run: self::noop());

        self::assertCount(1, $plan->stepsFor($create, before: false));
    }

    #[Test]
    public function stepsKeepTheirAttachmentOrder(): void
    {
        $change = self::change();
        $order = [];
        $plan = self::plan($change)
            ->withStep(before: 'add_column orders.payload', run: self::marker($order, 'first'))
            ->withStep(before: 'add_column orders.payload', run: self::marker($order, 'second'));

        $steps = $plan->stepsFor($change, before: true);
        self::assertCount(2, $steps);
        foreach ($steps as $step) {
            ($step->run)(self::session());
        }
        self::assertSame(['first', 'second'], $order);
    }

    /**
     * @param list<string> $order
     *
     * @return \Closure(\Nandan108\Attrecord\DbSession): void
     */
    private static function marker(array &$order, string $label): \Closure
    {
        return static function (\Nandan108\Attrecord\DbSession $session) use (&$order, $label): void {
            $session->inTransaction();
            $order[] = $label;
        };
    }

    private static function session(): \Nandan108\Attrecord\DbSession
    {
        return new \Nandan108\Attrecord\Test\CapturingDbSession();
    }

    #[Test]
    public function attachingReturnsANewPlanAndLeavesTheOriginalAlone(): void
    {
        $original = self::plan(self::change());
        $withStep = $original->withStep(before: 'add_column orders.payload', run: self::noop());

        self::assertSame([], $original->steps);
        self::assertCount(1, $withStep->steps);
        self::assertSame($original->changes, $withStep->changes);
    }

    #[Test]
    public function aSelectorMatchingNothingIsRecordedRatherThanRefused(): void
    {
        // This is the normal state on a converged install: the change was applied on an earlier
        // boot, the plan is empty, and the consumer's code still attaches its step. Throwing here
        // would fail every subsequent boot.
        $plan = self::plan(self::change())->withStep(after: 'add_column orders.something_else', run: self::noop());

        self::assertCount(1, $plan->unmatchedSteps());
        self::assertSame('add_column orders.something_else', $plan->unmatchedSteps()[0]->selector);
    }

    #[Test]
    public function anUnknownKindIsRefusedWhereItIsWritten(): void
    {
        // The one mistake that *can* be caught, since a legitimately-absent change and a typo look
        // identical at apply time. A kind that does not exist never will.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names no such change kind');
        self::plan()->withStep(before: 'ad_column orders.payload', run: self::noop());
    }

    #[Test]
    public function aSelectorWithoutATableIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "kind table.subject"');
        self::plan()->withStep(before: 'add_column', run: self::noop());
    }

    #[Test]
    public function placementMustBeChosen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one of before: or after:');
        self::plan()->withStep(run: self::noop());
    }

    #[Test]
    public function placementMustBeChosenOnlyOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::plan()->withStep(before: 'add_column orders.payload', after: 'add_column orders.payload', run: self::noop());
    }
}
