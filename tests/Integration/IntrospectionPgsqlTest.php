<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\PgsqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\PgsqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\GoldenRoundTripCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\IntrospectionCases;
use Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase;

/** @group Pgsql */
final class IntrospectionPgsqlTest extends PgsqlIntegrationTestCase
{
    use GoldenRoundTripCases;
    use IntrospectionCases;

    protected function normalizer(): ColumnNormalizer
    {
        return new PgsqlColumnNormalizer();
    }

    protected function introspector(): SchemaIntrospector
    {
        return new PgsqlIntrospector();
    }
}
