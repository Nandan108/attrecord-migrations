<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** The index gains a name but keeps its shape — recoverable without any declaration. */
#[Table(name: 'mig_markers')]
final class MarkerShapeRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[Index('idx_status_v2')]
    public string $status = '';

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';
}
