<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Tests\Integration\Cases\SchemaMarkerCases;
use Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase;

/** @group Sqlite */
final class SchemaMarkerSqliteTest extends SqliteIntegrationTestCase
{
    use SchemaMarkerCases;

    /** Both engines quote identifiers with double quotes, not backticks. */
    protected function q(string $identifier): string
    {
        return '"'.$identifier.'"';
    }
}
