<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * The two column types that exist only on the MySQL family, kept out of {@see TypeMatrixRecord}
 * because they cannot round-trip elsewhere: `Set` has no PG/SQLite equivalent (the producer throws
 * rather than silently degrade it), and `Bit` normalizes "unsure" on PG (its length is invisible
 * through that introspection path, and guessing is the one thing this pipeline never does).
 *
 * Exercised only by the MySQL golden round-trip — which is the point: these types are verified
 * where they are real, instead of being absent from the matrix entirely.
 */
#[Table(name: 'mig_mysql_only_types')]
final class MysqlOnlyTypesRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Set, enumValues: ['read', 'write', 'admin'], nullable: true)]
    public ?string $perms = null;

    #[Column(ColumnType::Bit, length: 8, nullable: true)]
    public ?string $flags = null;
}
