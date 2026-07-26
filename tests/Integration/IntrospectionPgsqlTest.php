<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\PgsqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase;

/** @group Pgsql */
final class IntrospectionPgsqlTest extends PgsqlIntegrationTestCase
{
    use IntrospectionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new PgsqlIntrospector();
    }
}
