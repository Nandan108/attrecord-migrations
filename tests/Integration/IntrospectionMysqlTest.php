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

    protected function normalizer(): ColumnNormalizer
    {
        return new MysqlColumnNormalizer();
    }

    protected function introspector(): SchemaIntrospector
    {
        return new MysqlIntrospector();
    }
}
