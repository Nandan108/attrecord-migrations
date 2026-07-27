<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Tests\Integration\Cases\EvolutionCases;
use Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase;

/** @group Sqlite */
final class EvolutionSqliteTest extends SqliteIntegrationTestCase
{
    use EvolutionCases;
}
