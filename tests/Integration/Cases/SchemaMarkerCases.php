<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\Plan;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerAbsentRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerBaseRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerDeclaredRenameRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerShapeRenameRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerSilentRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MarkerUnmanagedRecord;

/**
 * The markers that describe a table by what is *not* declared on it: an index rename (inferred or
 * declared), `#[Absent]`, and `#[Unmanaged]`.
 *
 * Each case converges one Record, then plans a later one against the database the first left
 * behind — the real shape of an upgrade, and the only way the interesting cases arise at all.
 *
 * @phpstan-require-extends \Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase|\Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase|\Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase
 */
trait SchemaMarkerCases
{
    /** Identifier quoting for the raw DDL these cases inject; overridden per backend if needed. */
    protected function q(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    private function markerMigrator(): SchemaMigrator
    {
        return new SchemaMigrator(Record::connection());
    }

    /**
     * Converge the starting shape, then plan `$next` against what it left behind.
     *
     * @param class-string<Record> $next
     */
    private function planAfterBase(string $next): Plan
    {
        $migrator = $this->markerMigrator();
        $migrator->apply($migrator->plan([MarkerBaseRecord::class]));

        return $migrator->plan([$next]);
    }

    public function testAnIndexRenameIsOneChange(): void
    {
        // Nothing is declared here: an orphaned add and an orphaned drop of identical shape are
        // read as the rename they are. Emitted separately the create is Safe and the drop is
        // Destructive, so a default-ceiling run would build the new index and keep the old one
        // forever — the failure this pairing exists to prevent.
        $plan = $this->planAfterBase(MarkerShapeRenameRecord::class);

        self::assertSame(['rename_index'], array_map(static fn ($c): string => $c->kind, $plan->changes));
        self::assertSame(ChangeClass::Safe, $plan->changes[0]->class);
        self::assertSame('idx_status_v2', $plan->changes[0]->subject);
        self::assertStringContainsString('idx_status', $plan->changes[0]->reason);

        $migrator = $this->markerMigrator();
        $migrator->apply($plan);
        self::assertTrue($migrator->plan([MarkerShapeRenameRecord::class])->isEmpty(), 'and it converged');
    }

    public function testADeclaredRenameSurvivesTheShapeChangingToo(): void
    {
        // Renamed *and* reshaped: no heuristic can relate the two, so without the declaration this
        // would be an unrelated create plus an unrelated drop.
        $plan = $this->planAfterBase(MarkerDeclaredRenameRecord::class);

        self::assertSame(['rename_index'], array_map(static fn ($c): string => $c->kind, $plan->changes));
        self::assertStringContainsString('declared rename', $plan->changes[0]->reason);

        $migrator = $this->markerMigrator();
        $migrator->apply($plan);
        self::assertTrue($migrator->plan([MarkerDeclaredRenameRecord::class])->isEmpty());
    }

    public function testDeclaringAnIndexAbsentMakesDroppingItSafe(): void
    {
        $plan = $this->planAfterBase(MarkerAbsentRecord::class);

        $index = $this->soleChange($plan, 'drop_index');
        self::assertSame(ChangeClass::Safe, $index->class, 'the declaration answers the ownership question');
        self::assertStringContainsString('declared absent since 1.4.0', $index->reason);

        // ...but the column stays Destructive, declaration or not: its values do not come back.
        $column = $this->soleChange($plan, 'drop_column');
        self::assertSame(ChangeClass::Destructive, $column->class);
        self::assertStringContainsString('declared absent since 1.4.0', $column->reason);
        self::assertStringContainsString('destroys its data', $column->reason);
    }

    public function testAnUndeclaredIndexStaysDestructive(): void
    {
        // The same table, the same drop, no declaration — the contrast that makes the case above
        // mean something. An index forbids nothing, so an unrecognised one is as likely to be an
        // operator's tuning index as our leftover.
        $plan = $this->planAfterBase(MarkerSilentRecord::class);

        self::assertSame(ChangeClass::Destructive, $this->soleChange($plan, 'drop_index')->class);
        self::assertStringContainsString('not declared', $this->soleChange($plan, 'drop_index')->reason);
    }

    public function testAnAbsentIndexDropsAtTheDefaultCeiling(): void
    {
        // The point of the reclassification, end to end: converging needs no operator decision.
        $migrator = $this->markerMigrator();
        $migrator->apply($migrator->plan([MarkerBaseRecord::class]));
        $migrator->apply($migrator->plan([MarkerAbsentRecord::class])); // default ceiling: Safe

        $remaining = $migrator->plan([MarkerAbsentRecord::class]);
        self::assertSame(
            ['drop_column'],
            array_map(static fn ($c): string => $c->kind, $remaining->changes),
            'the index went; only the destructive column drop is left for a human',
        );
    }

    public function testAnUnmanagedObjectIsNeverProposedForDropping(): void
    {
        $migrator = $this->markerMigrator();
        $migrator->apply($migrator->plan([MarkerBaseRecord::class]));

        // Someone else adds an index, the way a DBA does after a slow query.
        static::$session->exec(
            'CREATE INDEX '.$this->q('idx_dba_tuning').' ON '.$this->q('mig_markers').' ('.$this->q('code').')',
        );

        // The Record declares that index and the `code` column as another authority's, and says
        // nothing else new — so there is nothing to do at all.
        self::assertTrue(
            $migrator->plan([MarkerUnmanagedRecord::class])->isEmpty(),
            'an object declared unmanaged produces no change, not even a reported one',
        );
    }

    /** The one change of `$kind` in the plan, failing loudly if there is not exactly one. */
    private function soleChange(Plan $plan, string $kind): \Nandan108\AttrecordMigrations\Plan\PlannedChange
    {
        $found = array_values(array_filter($plan->changes, static fn ($c): bool => $c->kind === $kind));
        self::assertCount(1, $found, sprintf(
            'expected exactly one %s; plan was: %s',
            $kind,
            implode(', ', array_map(static fn ($c): string => $c->kind.'('.$c->subject.')', $plan->changes)),
        ));

        return $found[0];
    }
}
