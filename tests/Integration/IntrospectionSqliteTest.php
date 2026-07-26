<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SqliteIntrospector;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase;

/** @group Sqlite */
final class IntrospectionSqliteTest extends SqliteIntegrationTestCase
{
    use IntrospectionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new SqliteIntrospector();
    }
}
