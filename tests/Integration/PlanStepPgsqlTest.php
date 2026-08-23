<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Tests\Integration\Cases\PlanStepCases;
use Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase;

/** @group Pgsql */
final class PlanStepPgsqlTest extends PgsqlIntegrationTestCase
{
    use PlanStepCases;

    /** Both engines quote identifiers with double quotes, not backticks. */
    protected function q(string $identifier): string
    {
        return '"'.$identifier.'"';
    }
}
