<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\MysqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\GoldenRoundTripCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase;

/** @group Mysql */
final class IntrospectionMysqlTest extends MysqlIntegrationTestCase
{
    use GoldenRoundTripCases;
    use IntrospectionCases;

    protected function normalizer(): ColumnNormalizer
    {
        return new MysqlColumnNormalizer();
    }

    protected function introspector(): SchemaIntrospector
    {
        return new MysqlIntrospector();
    }
}
