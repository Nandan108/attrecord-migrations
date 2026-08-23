<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** The same shape as {@see MarkerAbsentRecord}, declaring nothing — the undeclared baseline. */
#[Table(name: 'mig_markers')]
final class MarkerSilentRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $status = '';
}
