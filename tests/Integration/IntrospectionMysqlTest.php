<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase;

/** @group Mysql */
final class IntrospectionMysqlTest extends MysqlIntegrationTestCase
{
    use IntrospectionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new MysqlIntrospector();
    }
}
