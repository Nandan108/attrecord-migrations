<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\RefTargetRecord;

/**
 * One real-database case per **kind** of drift, closing the gap the unit differ tests leave: those
 * diff against hand-built `LiveTable`s, so a wrong assumption about what an engine *reports* passes
 * unit-green and fails in production. Here the drift is injected as raw DDL into a converged
 * database, so the live side comes from the engine itself, and the loop closes end to end:
 *
 *   converge → inject drift → plan (assert kind + class) → apply at that ceiling → re-plan EMPTY
 *
 * Each backend supplies its own {@see driftMatrix()} — the DDL that creates the drift *and* the
 * expectation. Stating the expectation per backend is deliberate: several drifts are **invisible**
 * on a given engine by design (SQLite stores affinity, not width; enum members are MySQL-only), and
 * an explicitly empty expectation pins that documented limitation instead of hiding it. Every
 * backend must have an entry for every scenario — a missing key fails rather than silently skips.
 */
trait DriftMatrixCases
{
    /** @var list<class-string<Record>> */
    private static array $driftClasses = [RefTargetRecord::class, KitchenSinkRecord::class];

    /**
     * Per-backend drift scenarios: DDL that injects the drift, plus the expected plan.
     *
     * `kinds` empty means "this engine cannot see this drift" (documented limitation) and the plan
     * must come back empty; otherwise the plan's change kinds must match exactly, in order.
     *
     * @return array<string, array{ddl: list<string>, kinds: list<string>, class?: ChangeClass, mayReject?: bool}>
     */
    abstract protected function driftMatrix(): array;

    private function driftMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    /** The FK's declared name, read from the schema rather than hard-coded (attrecord auto-names it). */
    protected static function kitchenSinkFkName(): string
    {
        return \Nandan108\Attrecord\Schema\TableSchema::fromClass(KitchenSinkRecord::class)->foreignKeys[0]->constraintName;
    }

    // ---- column facets ----

    public function testDriftWidenVarchar(): void
    {
        $this->runDriftScenario('widen_varchar');
    }

    public function testDriftNarrowVarchar(): void
    {
        $this->runDriftScenario('narrow_varchar');
    }

    public function testDriftNullableTightening(): void
    {
        $this->runDriftScenario('nullable_tighten');
    }

    public function testDriftDefaultOnly(): void
    {
        $this->runDriftScenario('default_drift');
    }

    public function testDriftIntegerWidening(): void
    {
        $this->runDriftScenario('int_widen');
    }

    public function testDriftDecimalPrecisionWidening(): void
    {
        $this->runDriftScenario('precision_widen');
    }

    public function testDriftEnumMemberAppend(): void
    {
        $this->runDriftScenario('enum_member_append');
    }

    // ---- structural ----

    public function testDriftDeclaredRename(): void
    {
        $this->runDriftScenario('rename_column');
    }

    public function testDriftIndexReshape(): void
    {
        $this->runDriftScenario('index_reshape');
    }

    public function testDriftForeignKeyActionChange(): void
    {
        $this->runDriftScenario('fk_action_change');
    }

    public function testDriftUndeclaredForeignKey(): void
    {
        $this->runDriftScenario('undeclared_fk');
    }

    /**
     * Converge, inject the scenario's drift, then assert the plan's shape, its classification, and
     * — for anything auto-applicable — that applying it lands back on an empty plan.
     */
    private function runDriftScenario(string $key): void
    {
        $matrix = $this->driftMatrix();
        self::assertArrayHasKey($key, $matrix, "each backend must state an expectation for the '{$key}' scenario");
        $spec = $matrix[$key];

        $migrator = $this->driftMigrator();
        $migrator->apply($migrator->plan(self::$driftClasses));
        self::assertTrue($migrator->plan(self::$driftClasses)->isEmpty(), 'precondition: converged before drift is injected');

        foreach ($spec['ddl'] as $sql) {
            static::$session->exec($sql);
        }

        $plan = $migrator->plan(self::$driftClasses);
        $describe = static fn (): string => implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}[{$c->class->value}]({$c->subject}: {$c->reason})",
            $plan->changes,
        ));

        if ([] === $spec['kinds']) {
            self::assertTrue($plan->isEmpty(), "'{$key}' is not representable on this engine and must not plan a change; got: ".$describe());

            return;
        }

        self::assertSame($spec['kinds'], array_map(static fn ($c): string => $c->kind, $plan->changes), "'{$key}' plan shape; got: ".$describe());
        $class = $spec['class'] ?? ChangeClass::Safe;
        foreach ($plan->changes as $change) {
            self::assertSame($class, $change->class, "'{$key}' classification of {$change->kind}; got: ".$describe());
        }
        if ($spec['mayReject'] ?? false) {
            self::assertTrue(
                array_reduce(
                    $plan->changes,
                    static fn (bool $carry, PlannedChange $c): bool => $carry || $c->mayRejectExistingRows,
                    false,
                ),
                "'{$key}' must be flagged as able to reject existing rows",
            );
        }

        if (ChangeClass::Manual === $class) {
            foreach ($plan->changes as $change) {
                self::assertSame([], $change->statements, 'Manual carries a reason, never SQL');
            }
            // Even at the highest ceiling, Manual is not executed — the drift is still there after.
            $migrator->apply($plan, allow: ChangeClass::Destructive);
            self::assertFalse($migrator->plan(self::$driftClasses)->isEmpty(), 'Manual must never be auto-applied');

            return;
        }

        // One apply must be enough: convergence in a single pass is the contract, not an accident.
        $migrator->apply($plan, allow: $class);
        $after = $migrator->plan(self::$driftClasses);
        self::assertTrue($after->isEmpty(), "'{$key}' must converge; residual: ".implode(' | ', array_map(
            static fn ($c): string => "{$c->kind}({$c->subject}: {$c->reason})",
            $after->changes,
        )));
    }
}
