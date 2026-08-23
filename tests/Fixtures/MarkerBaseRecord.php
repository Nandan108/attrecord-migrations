<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * The starting shape of `mig_markers`. Its siblings — {@see MarkerShapeRenameRecord},
 * {@see MarkerDeclaredRenameRecord}, {@see MarkerAbsentRecord}, {@see MarkerSilentRecord},
 * {@see MarkerUnmanagedRecord} — describe the *same* table at later points in its life, which is
 * how the evolution markers are exercised: converge this one, then plan a later one against the
 * database it left behind.
 */
#[Table(name: 'mig_markers')]
final class MarkerBaseRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    #[Index('idx_status')]
    public string $status = '';

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';
}
