<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration\Cases;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Live\LiveTable;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\RefTargetRecord;

/**
 * Backend-agnostic introspection round-trip: create the kitchen-sink fixture via attrecord's
 * own DDL producer, introspect it, and assert the LiveTable mirrors the declared structure.
 * Per-dialect *type/default string* details belong to the normalizer tests, not here — these
 * cases only pin the structural facts every backend must agree on.
 */
trait IntrospectionCases
{
    abstract protected function introspector(): SchemaIntrospector;

    protected function createFixtureTables(): void
    {
        $dialect = Record::connection()->dialect;
        foreach ([RefTargetRecord::class, KitchenSinkRecord::class] as $class) {
            foreach (explode(";\n", $dialect->buildCreateTable(TableSchema::fromClass($class))) as $statement) {
                static::$session->exec($statement);
            }
        }
    }

    private function introspectSink(): LiveTable
    {
        $this->createFixtureTables();
        $live = $this->introspector()->introspectTable(static::$session, 'mig_kitchen_sink');
        $this->assertNotNull($live);

        return $live;
    }

    public function testMissingTableIntrospectsAsNull(): void
    {
        $this->assertNull($this->introspector()->introspectTable(static::$session, 'mig_nope'));
    }

    public function testColumnsRoundTrip(): void
    {
        $live = $this->introspectSink();

        $this->assertSame(
            array_keys(TableSchema::fromClass(KitchenSinkRecord::class)->columns),
            array_keys($live->columns),
            'all declared columns present, in order',
        );

        $this->assertTrue($live->columns['id']->autoIncrement, 'AI PK detected');
        $this->assertFalse($live->columns['sku']->nullable);
        $this->assertTrue($live->columns['delta']->nullable);
        $this->assertTrue($live->columns['meta']->nullable);
        $this->assertNotNull($live->columns['label']->rawDefault, "label carries a default ('')");
        // Raw introspection reality: MariaDB reports the *string* 'NULL' for a nullable no-default
        // column; PG/SQLite report SQL NULL. The introspector passes raw truth through — collapsing
        // the two is the normalizer's job (arch-migrations.md §4.2), so both are accepted here.
        $seenAtDefault = $live->columns['seen_at']->rawDefault;
        $this->assertTrue(
            null === $seenAtDefault || 'null' === strtolower($seenAtDefault),
            'seen_at has no effective default (got: '.var_export($seenAtDefault, true).')',
        );
        $this->assertFalse($live->columns['qty']->autoIncrement);
    }

    public function testPrimaryKeyRoundTrips(): void
    {
        $this->assertSame(['id'], $this->introspectSink()->primaryKey);
    }

    public function testUniqueKeyAndIndexRoundTrip(): void
    {
        $live = $this->introspectSink();

        $this->assertArrayHasKey('uniq_sku', $live->indexes);
        $this->assertTrue($live->indexes['uniq_sku']->unique);
        $this->assertSame(['sku'], $live->indexes['uniq_sku']->columns);

        $this->assertArrayHasKey('idx_status_created', $live->indexes);
        $this->assertFalse($live->indexes['idx_status_created']->unique);
        $this->assertSame(['status', 'created_at'], $live->indexes['idx_status_created']->columns);
    }

    public function testForeignKeyRoundTrips(): void
    {
        $live = $this->introspectSink();

        $this->assertCount(1, $live->foreignKeys);
        \assert([] !== $live->foreignKeys); // narrows array_key_first() to string for psalm
        $fk = $live->foreignKeys[array_key_first($live->foreignKeys)];
        $this->assertSame(['ref_id'], $fk->localColumns);
        $this->assertSame('mig_ref_targets', $fk->referencedTable);
        $this->assertSame(['id'], $fk->referencedColumns);
        $this->assertSame('SET NULL', $fk->onDelete);
    }
}
