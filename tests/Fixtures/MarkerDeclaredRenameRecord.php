<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/** Renamed **and** reshaped in one release: nothing but the declaration relates the two. */
#[Table(name: 'mig_markers')]
#[Index('idx_status_v2', columns: ['status', 'code'], renamedFrom: 'idx_status', renamedSince: '1.4.0')]
final class MarkerDeclaredRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $status = '';

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';
}
