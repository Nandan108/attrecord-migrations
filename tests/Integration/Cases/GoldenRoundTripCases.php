<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\RefTargetRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\TypeMatrixRecord;

/**
 * THE golden invariant (arch-migrations.md §4.2): a table freshly created from its Record must
 * normalize to *identical* tuples on both sides — desired (attributes) and live (introspected) —
 * for every column, on every backend. Any inequality here is a normalizer/introspector bug that
 * would surface to consumers as a false-positive ALTER; any "unsure" is a vocabulary hole.
 *
 * This is the empty-plan guarantee before the differ even exists.
 */
trait GoldenRoundTripCases
{
    abstract protected function introspector(): SchemaIntrospector;

    abstract protected function normalizer(): ColumnNormalizer;

    public function testFreshlyCreatedTableNormalizesIdenticallyOnBothSides(): void
    {
        $this->assertRoundTrips([RefTargetRecord::class, KitchenSinkRecord::class]);
    }

    /**
     * Every portable column type, so no type's normalization rests on inspection alone. Types that
     * exist on only one engine are covered by that engine's own runner.
     */
    public function testEveryPortableColumnTypeRoundTrips(): void
    {
        $this->assertRoundTrips([TypeMatrixRecord::class]);
    }

    /**
     * Create each Record's table with attrecord's own DDL producer, then assert the introspected
     * shape normalizes to exactly the tuple the attributes describe.
     *
     * @param list<class-string<Record>> $classes
     */
    protected function assertRoundTrips(array $classes): void
    {
        $dialect = Record::connection()->dialect;
        foreach ($classes as $class) {
            foreach (explode(";\n", $dialect->buildCreateTable(TableSchema::fromClass($class))) as $statement) {
                static::$session->exec($statement);
            }
        }

        $normalizer = $this->normalizer();
        foreach ($classes as $class) {
            $schema = TableSchema::fromClass($class);
            $live = $this->introspector()->introspectTable(static::$session, $schema->tableName);
            $this->assertNotNull($live, "{$schema->tableName} must introspect");

            foreach ($schema->columns as $colName => $desired) {
                $this->assertArrayHasKey($colName, $live->columns, "{$schema->tableName}.{$colName} must exist live");
                $desiredNorm = $normalizer->normalizeDesired($desired);
                $liveNorm = $normalizer->normalizeLive($live->columns[$colName]);

                $this->assertFalse($desiredNorm->isUnsure(), "desired {$schema->tableName}.{$colName}: ".(string) $desiredNorm->unsureReason);
                $this->assertFalse($liveNorm->isUnsure(), "live {$schema->tableName}.{$colName}: ".(string) $liveNorm->unsureReason);
                \assert(null !== $desiredNorm->tuple && null !== $liveNorm->tuple);

                $this->assertSame(
                    [],
                    $desiredNorm->tuple->diffFacets($liveNorm->tuple),
                    "{$schema->tableName}.{$colName} must round-trip with no drift — desired "
                    .var_export($desiredNorm->tuple, true).' vs live '.var_export($liveNorm->tuple, true),
                );
            }
        }
    }
}
