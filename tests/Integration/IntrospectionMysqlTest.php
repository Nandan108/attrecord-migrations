<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\MysqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Tests\Fixtures\GeneratedColumnRecord;
use Nandan108\AttrecordMigrations\Tests\Fixtures\MysqlOnlyTypesRecord;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\GoldenRoundTripCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase;

/** @group Mysql */
final class IntrospectionMysqlTest extends MysqlIntegrationTestCase
{
    use GoldenRoundTripCases;
    use IntrospectionCases;

    /** SET and BIT exist only here, so this is the only backend that can prove they round-trip. */
    public function testMysqlOnlyTypesRoundTrip(): void
    {
        $this->assertRoundTrips([MysqlOnlyTypesRecord::class]);
    }

    /**
     * Generated columns, whose nullability and stored expression the engine decides for itself.
     * Round-tripping them is what keeps a correct database from reporting permanent phantom
     * drift — see {@see GeneratedColumnRecord}.
     */
    public function testGeneratedColumnsRoundTrip(): void
    {
        $this->assertRoundTrips([GeneratedColumnRecord::class]);
    }

    /**
     * A constraint and an index whose names are **numeric strings** — `1`, `2`.
     *
     * PHP turns a numeric-string array key into an int, so a name that survives every explicit
     * `(string)` cast on the way into an accumulator comes back out as an int, and the introspector
     * hands it to a string-typed constructor: TypeError, on a table that is in no way malformed.
     * Not a hypothetical shape — a MariaDB that names an unnamed `FOREIGN KEY` with a bare ordinal
     * produces exactly this, which is how a real InvFlux database turned out to be uninspectable.
     *
     * Named explicitly here rather than relying on that auto-naming, because which form an engine
     * picks varies by version: the mechanism is what must be pinned, not one engine's spelling of
     * it. No attrecord-built fixture can reproduce it either — the DDL producer always names its
     * constraints.
     */
    public function testNumericallyNamedConstraintsIntrospectAsStrings(): void
    {
        static::$session->exec('DROP TABLE IF EXISTS mig_numeric_fk_child');
        static::$session->exec('DROP TABLE IF EXISTS mig_numeric_fk_parent');
        static::$session->exec('CREATE TABLE mig_numeric_fk_parent (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        static::$session->exec(
            'CREATE TABLE mig_numeric_fk_child (
                id        INT UNSIGNED NOT NULL PRIMARY KEY,
                parent_id INT UNSIGNED NULL,
                label     VARCHAR(32) NULL,
                KEY `2` (`label`),
                CONSTRAINT `1` FOREIGN KEY (parent_id) REFERENCES mig_numeric_fk_parent(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
        );

        $live = (new MysqlIntrospector())->introspectTable(static::$session, 'mig_numeric_fk_child');

        self::assertNotNull($live);

        self::assertCount(1, $live->foreignKeys);
        $fk = $live->foreignKeys['1'] ?? null;
        self::assertNotNull($fk, 'the foreign key is keyed by its name');
        self::assertSame('1', $fk->name);
        self::assertSame(['parent_id'], $fk->localColumns);
        self::assertSame('mig_numeric_fk_parent', $fk->referencedTable);

        $index = $live->indexes['2'] ?? null;
        self::assertNotNull($index, 'the index is keyed by its name');
        self::assertSame('2', $index->name);
        self::assertSame(['label'], $index->columns);
    }

    protected function normalizer(): ColumnNormalizer
    {
        return new MysqlColumnNormalizer();
    }

    protected function introspector(): SchemaIntrospector
    {
        return new MysqlIntrospector();
    }
}
