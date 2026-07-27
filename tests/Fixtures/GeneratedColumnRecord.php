<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Record;

/**
 * Generated columns, both storage modes, with expressions the engine is known to rewrite.
 *
 * These are the columns most likely to *look* drifted while being exactly as declared: the engine
 * decides their nullability, and it stores its own spelling of the expression rather than the one
 * written here (`(a IS NULL AND b IS NULL)` comes back as `` `a` is null and `b` is null ``). A
 * table that re-plans non-empty right after being created is the worst failure this pipeline can
 * have — the consumer sees permanent phantom drift on a database that is perfectly correct — so
 * both modes are pinned by the golden round-trip.
 *
 * MySQL-family only, alongside {@see MysqlOnlyTypesRecord}: PostgreSQL supports `STORED` alone and
 * SQLite has its own expression rules, so the *engine-rewriting* behaviour this guards against is
 * verified where it is real. The dialect-independent half of the rule — which facets a generated
 * column may be compared on — is unit-tested on {@see \Nandan108\AttrecordMigrations\Normalize\ColumnTuple}.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist to declare the table's DDL.
 */
#[Table(name: 'mig_generated_columns')]
final class GeneratedColumnRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $requested = 0;

    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $received = 0;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $closed_at = null;

    /** VIRTUAL: computed on read, stored nowhere. */
    #[Column(
        ColumnType::IntUnsigned,
        generatedAs: 'GREATEST(0, CAST(`requested` AS SIGNED) - CAST(`received` AS SIGNED))',
        generatedMode: GeneratedColumnMode::Virtual,
    )]
    public int $outstanding = 0;

    /** STORED, and a boolean expression — the shape engines re-spell most aggressively. */
    #[Column(
        ColumnType::Bool,
        generatedAs: '(`closed_at` IS NULL)',
        generatedMode: GeneratedColumnMode::Stored,
    )]
    public bool $is_open = false;
}
