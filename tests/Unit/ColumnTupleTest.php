<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;
use PHPUnit\Framework\TestCase;

/**
 * The facet-comparison rule, in the one place it is dialect-independent.
 *
 * Generated columns are the interesting case: the engine — not the declaration — owns their
 * nullability and the spelling of their expression, so comparing either is how a perfectly
 * correct database ends up reporting drift forever. Whether a *given* engine actually rewrites a
 * given expression is an integration question (see the MySQL golden round-trip); that it must not
 * matter is this test's job.
 */
final class ColumnTupleTest extends TestCase
{
    private static function tuple(
        bool $nullable = false,
        ?string $generated = null,
        string $type = 'int',
        ?int $length = null,
    ): ColumnTuple {
        return new ColumnTuple(
            type: $type,
            unsigned: true,
            length: $length,
            precision: null,
            scale: null,
            nullable: $nullable,
            default: null,
            autoIncrement: false,
            generated: $generated,
            members: null,
        );
    }

    public function testGeneratedColumnsIgnoreNullabilityDifferences(): void
    {
        // MariaDB reports a generated column as nullable whatever the declaration said, so that
        // facet stays skipped. The expressions here are what the *normalizer* produces for the
        // same column on both sides — engine re-spelling is absorbed before this comparison, not
        // by it (see ColumnNormalizerTest).
        $desired = self::tuple(nullable: false, generated: 'closed_atisnull');
        $live = self::tuple(nullable: true, generated: 'closed_atisnull');

        self::assertSame([], $desired->diffFacets($live));
        self::assertTrue($desired->equals($live));
    }

    public function testGeneratedExpressionChangeIsReported(): void
    {
        // A genuinely changed expression must surface. It was previously skipped, which meant a
        // corrected expression produced no planned change at all: the repair reached new installs
        // and no existing one. The differ routes this facet to Manual — reported, never
        // auto-applied.
        $desired = self::tuple(generated: 'greatest(0,cast(aassigned)-cast(bassigned))');
        $live = self::tuple(generated: 'greatest(0,a-b)');

        self::assertSame(['generated'], $desired->diffFacets($live));
        self::assertFalse($desired->equals($live));
    }

    public function testGeneratedColumnsStillCompareEveryOtherFacet(): void
    {
        // Ignoring two facets must not turn a generated column into a blind spot: a widened
        // type is still a type change, and the differ must be told about it.
        $desired = self::tuple(generated: 'a + b', type: 'varchar', length: 191);
        $live = self::tuple(generated: 'a + b', type: 'varchar', length: 64);

        self::assertSame(['length'], $desired->diffFacets($live));
    }

    public function testGainingOrLosingGenerationIsRealDrift(): void
    {
        // Only one side generated: the exemption does not apply, because this is exactly the
        // change that must not slip through — a plain column turning computed, or the reverse.
        $plain = self::tuple(nullable: true);
        $generated = self::tuple(nullable: true, generated: 'a + b');

        self::assertSame(['generated'], $plain->diffFacets($generated));
        self::assertSame(['generated'], $generated->diffFacets($plain));
    }

    public function testPlainColumnsCompareNullabilityAsBefore(): void
    {
        self::assertSame(['nullable'], self::tuple(nullable: false)->diffFacets(self::tuple(nullable: true)));
    }
}
