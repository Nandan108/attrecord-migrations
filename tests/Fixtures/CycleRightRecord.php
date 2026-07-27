<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/** The other half of the cycle — see {@see CycleLeftRecord}. */
#[Table(name: 'mig_cycle_right')]
#[ForeignKey(column: 'left_id', references: CycleLeftRecord::class, onDelete: ForeignKeyAction::Cascade)]
final class CycleRightRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $value = '';

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $left_id = null;
}
