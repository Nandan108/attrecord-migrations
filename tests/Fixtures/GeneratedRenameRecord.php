<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Record;

/**
 * A declared rename of a column a generated column depends on — **and that generated column carries
 * an index**, which is the whole point of the fixture.
 *
 * An unindexed dependent converges whether or not the rename preserves indexes, so it cannot tell
 * the two implementations apart. This one can: anything that drops `value_uint` on its way past
 * takes `idx_value_uint` with it, silently, and the table is left correct but slower.
 *
 * Modelled on a real consumer table (`invflux_subject_identifiers`), where the generated column
 * exists for its index and nothing else reads it directly.
 */
#[Table(name: 'mig_gen_rename')]
#[Index('idx_value_uint', columns: ['value_uint'])]
final class GeneratedRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64, renamedFrom: 'value', renamedSince: '1.4.0')]
    public string $ident_value = '';

    #[Column(ColumnType::BigIntUnsigned, nullable: true, generatedAs: 'CAST(`ident_value` AS UNSIGNED)', generatedMode: GeneratedColumnMode::Virtual)]
    public ?int $value_uint = null;
}
