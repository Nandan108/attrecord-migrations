<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Ledger;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * One converge run, for **forensics only** (the design contract §5.3): what did the upgrade execute
 * on this site, when, and did it finish. The differ never reads this table — truth about the live
 * schema comes from the live schema. (The one ledger-authoritative table is
 * {@see SchemaStepRecord}, for run-once data steps.).
 *
 * Dogfood note: the migrations package uses attrecord itself for its ledger — DDL from this
 * Record, writes via `save()`, `statements_json` array-cast.
 *
 * Subclass it with your own `#[Table(name:)]` to place the ledger under a project-specific name,
 * then pass the subclass to {@see \Nandan108\AttrecordMigrations\SchemaMigrator::__construct()}.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Public forensic surface.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
#[Table(name: 'attrecord_schema_runs')]
#[Index('idx_started', columns: ['started_at'])]
class SchemaRunRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** Fingerprint of the desired model set this run converged toward. */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $fingerprint = '';

    /**
     * Executed statements + outcomes, in order: [{sql, table, kind, subject, class, ok, error?}].
     *
     * @var list<array<string, mixed>>
     */
    #[Column(ColumnType::Json)]
    public array $statements_json = [];

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $started_at = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $finished_at = null;

    /** Null on success; the failing statement's error message otherwise. */
    #[Column(ColumnType::Text, nullable: true)]
    public ?string $error = null;
}
