<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * A table with a table-level CHECK, in the shape that motivates the feature: a rule that holds
 * *conditionally*, which no column definition can express.
 *
 * Portable SQL only — this fixture is created and converged for real on all three backends.
 */
#[Table(name: 'mig_checked')]
#[Check('archived_needs_reason', 'archived = 0 OR reason IS NOT NULL')]
final class CheckedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $archived = 0;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $reason = null;
}
