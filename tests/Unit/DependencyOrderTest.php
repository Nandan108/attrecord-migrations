<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Plan\DependencyOrder;
use PHPUnit\Framework\TestCase;

#[Table(name: 'ord_parent')]
final class OrdParentRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;
}

#[Table(name: 'ord_child')]
#[ForeignKey(column: 'parent_id', references: OrdParentRecord::class)]
final class OrdChildRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $parent_id = null;
}

#[Table(name: 'ord_grandchild')]
#[ForeignKey(column: 'child_id', references: OrdChildRecord::class)]
final class OrdGrandchildRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $child_id = null;
}

/** A tree: the FK points at its own table, which one CREATE satisfies. */
#[Table(name: 'ord_tree')]
#[ForeignKey(column: 'parent_id', references: OrdTreeRecord::class)]
final class OrdTreeRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $parent_id = null;
}

/** Points at a table no Record in the set declares — unmanaged, therefore not a dependency. */
#[Table(name: 'ord_external_ref')]
#[ForeignKey(column: 'outside_id', references: 'some_unmanaged_table')]
final class OrdExternalRefRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $outside_id = null;
}

#[Table(name: 'ord_loop_a')]
#[ForeignKey(column: 'b_id', references: OrdLoopBRecord::class)]
final class OrdLoopARecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $b_id = null;
}

#[Table(name: 'ord_loop_b')]
#[ForeignKey(column: 'a_id', references: OrdLoopARecord::class)]
final class OrdLoopBRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $a_id = null;
}

/**
 * Creation order derived from the declared FK graph — the property that makes a fresh install work
 * without the caller hand-sorting anything.
 */
final class DependencyOrderTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    public function testParentsComeBeforeChildrenWhateverTheInputOrder(): void
    {
        $sorted = DependencyOrder::sort([OrdGrandchildRecord::class, OrdChildRecord::class, OrdParentRecord::class]);

        self::assertSame([OrdParentRecord::class, OrdChildRecord::class, OrdGrandchildRecord::class], $sorted);
    }

    public function testAlreadyCorrectOrderIsLeftAlone(): void
    {
        $input = [OrdParentRecord::class, OrdChildRecord::class, OrdGrandchildRecord::class];

        self::assertSame($input, DependencyOrder::sort($input));
    }

    public function testUnconstrainedSetKeepsInputOrder(): void
    {
        // Stability matters: an arbitrary reshuffle would churn the fingerprint for no reason.
        $input = [OrdExternalRefRecord::class, OrdParentRecord::class, OrdTreeRecord::class];

        self::assertSame($input, DependencyOrder::sort($input));
    }

    public function testSelfReferenceIsNotADependency(): void
    {
        self::assertSame([OrdTreeRecord::class], DependencyOrder::sort([OrdTreeRecord::class]));
    }

    public function testForeignKeyToAnUnmanagedTableIsIgnored(): void
    {
        // The target is created by other means (or simply not converged); unmanaged objects are
        // invisible to this pipeline, never a missing dependency.
        self::assertSame([OrdExternalRefRecord::class], DependencyOrder::sort([OrdExternalRefRecord::class]));
    }

    public function testChildWithoutItsParentInTheSetIsNotADependency(): void
    {
        self::assertSame([OrdChildRecord::class], DependencyOrder::sort([OrdChildRecord::class]));
    }

    public function testMutualReferenceDefersOneEdgeInsteadOfFailing(): void
    {
        // No order satisfies a loop with both FKs inline, so one is deferred: B is created without
        // its FK to A, A is created (its target now exists), then B's constraint is added.
        $resolution = DependencyOrder::resolve([OrdLoopARecord::class, OrdLoopBRecord::class]);

        self::assertSame([OrdLoopBRecord::class, OrdLoopARecord::class], $resolution->classes);
        self::assertTrue($resolution->hasDeferred());
        self::assertSame(['fk_loop_b_a_id'], $resolution->deferredFor(OrdLoopBRecord::class));
        self::assertSame([], $resolution->deferredFor(OrdLoopARecord::class), 'only one edge of the loop is deferred');
    }

    public function testAcyclicSetDefersNothing(): void
    {
        $resolution = DependencyOrder::resolve([OrdGrandchildRecord::class, OrdChildRecord::class, OrdParentRecord::class]);

        self::assertFalse($resolution->hasDeferred());
        self::assertSame([], $resolution->deferredFor(OrdChildRecord::class));
    }
}
