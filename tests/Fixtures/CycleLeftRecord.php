<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * Half of a mutually-referencing pair, modelled on the shape that motivated deferred FKs: a
 * dimension table whose `default_value` points into its own values table, which in turn points
 * back at the dimension. Both FKs are nullable — a cycle of NOT NULL FKs could never be populated.
 */
#[Table(name: 'mig_cycle_left')]
#[ForeignKey(column: 'default_right_id', references: CycleRightRecord::class, onDelete: ForeignKeyAction::Restrict)]
final class CycleLeftRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $default_right_id = null;
}
