<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

use Nandan108\AttrecordMigrations\Plan\PlannedChange;

/**
 * A statement of an applied plan failed. The apply run stops at the failing statement (there is no
 * atomic converge to roll back — MySQL has no transactional DDL); everything executed before it is
 * already recorded in the schema-runs ledger, and the right recovery is to fix the cause and
 * **re-plan** — the next plan is computed from live truth, so completed changes simply no longer
 * appear (the design contract §5.2).
 *
 * @psalm-suppress PossiblyUnusedProperty Public error surface.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class MigrationFailedException extends \RuntimeException
{
    public function __construct(
        public readonly PlannedChange $change,
        public readonly string $failedSql,
        \Throwable $previous,
    ) {
        parent::__construct(
            "Migration statement failed on {$change->table} ({$change->kind} {$change->subject}): {$previous->getMessage()}\nSQL: {$failedSql}",
            0,
            $previous,
        );
    }
}
