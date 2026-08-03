<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Ledger;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * The run-once data-step ledger (the design contract §6.2): one row per executed step key. For
 * these rows — and only these — the ledger **is** authoritative: a data-shape transform has no
 * live schema state to introspect, so "has it run?" can only be answered here. Steps should still
 * be written idempotently where the transform allows (a *partial* restore can desynchronize
 * "ran" from "applied").
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
#[Table(name: 'attrecord_schema_steps', primaryKey: 'step_key')]
class SchemaStepRecord extends Record
{
    /** Consumer-chosen, globally-ordered key, e.g. `2026-07-wrap-payload-json`. */
    #[Column(ColumnType::VarChar, length: 191)]
    public string $step_key = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $applied_at = null;
}
