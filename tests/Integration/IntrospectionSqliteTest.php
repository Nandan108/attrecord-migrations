<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SqliteIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\SqliteColumnNormalizer;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\GoldenRoundTripCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase;

/** @group Sqlite */
final class IntrospectionSqliteTest extends SqliteIntegrationTestCase
{
    use GoldenRoundTripCases;
    use IntrospectionCases;

    protected function normalizer(): ColumnNormalizer
    {
        return new SqliteColumnNormalizer();
    }

    protected function introspector(): SchemaIntrospector
    {
        return new SqliteIntrospector();
    }
}
