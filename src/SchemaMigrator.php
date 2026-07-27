<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Diff\SchemaDiffer;
use Nandan108\AttrecordMigrations\Ledger\SchemaRunRecord;
use Nandan108\AttrecordMigrations\Ledger\SchemaStepRecord;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\DependencyOrder;
use Nandan108\AttrecordMigrations\Plan\Plan;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;

/**
 * The facade (arch-migrations.md §3): `plan()` / `apply()` / `dataStep()` / `fingerprint()`.
 *
 * - `plan()` is **pure** — introspection reads only, always safe to call, inspectable output.
 * - `apply()` is explicit and guarded: advisory-locked, ceiling-filtered (Safe by default,
 *   Destructive by opt-in, Manual never), per-statement execution with no atomic-converge
 *   pretense (§5.2), every run recorded in the schema-runs ledger (§5.3, forensics only).
 * - `dataStep()` is the run-once registry for data-shape transforms invisible to the differ
 *   (§6.2) — the one place the ledger is authoritative.
 *
 * The migrator binds all its Record work (the two ledger tables) to its own Connection via
 * `Record::usingConnection()`, so it neither depends on nor disturbs the consumer's global
 * attrecord binding.
 */
final class SchemaMigrator
{
    public const LOCK_NAME = 'attrecord_migrations';
    public const LOCK_TIMEOUT_SECONDS = 30;

    private readonly DialectSupport $support;
    private readonly SchemaDiffer $differ;
    private bool $ledgerInstalled = false;

    /**
     * @param class-string<SchemaRunRecord>  $runRecordClass  subclass the built-in Record with your own `#[Table(name:)]` to put the run ledger under your project's naming instead of the generic `attrecord_schema_runs`
     * @param class-string<SchemaStepRecord> $stepRecordClass same, for the run-once step ledger
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $runRecordClass = SchemaRunRecord::class,
        private readonly string $stepRecordClass = SchemaStepRecord::class,
    ) {
        $this->support = DialectSupport::for($connection->dialect);
        $this->differ = new SchemaDiffer($this->support->normalizer, $this->support->emitter, $connection->dialect);
    }

    /**
     * Diff every Record class against the live database. Pure: executes no DDL, takes no lock.
     * Pass them in any order — creation order is derived from the declared foreign keys.
     *
     * @param list<class-string<Record>> $recordClasses
     */
    public function plan(array $recordClasses): Plan
    {
        // Ordering is derived, not demanded of the caller: a table's FK targets must exist before
        // it does, and the attributes already say which those are. A cycle has no such order, so
        // one constraint per loop is deferred out of its CREATE and added at the end.
        $resolution = DependencyOrder::resolve($recordClasses);

        $changes = [];
        $deferredChanges = [];
        foreach ($resolution->classes as $class) {
            $schema = TableSchema::fromClass($class);
            $omit = $resolution->deferredFor($class);
            $live = $this->support->introspector->introspectTable($this->connection->session, $schema->tableName);
            $partial = is_a($class, PartiallyDeclared::class, true);
            foreach ($this->differ->diffTable($schema, $live, $omit, $partial) as $change) {
                // A deferred constraint's target may be created later in this same plan, so its
                // ADD has to trail every create rather than sit beside its own table's.
                if (\in_array($change->subject, $omit, true) && \in_array($change->kind, ['add_foreign_key', 'manual'], true)) {
                    $deferredChanges[] = $change;
                    continue;
                }
                $changes[] = $change;
            }
        }

        return new Plan([...$changes, ...$deferredChanges], Fingerprint::of($this->connection->dialect, $resolution->classes));
    }

    /**
     * Execute the plan's changes **within the ceiling** (`Safe` by default; pass
     * `ChangeClass::Destructive` to also run destructive changes; Manual never runs). Changes
     * beyond the ceiling are skipped, not errors — they stay visible in the plan for the consumer
     * to surface. Runs under an advisory lock; each statement executes individually (MySQL has no
     * transactional DDL — recovery from a mid-plan failure is re-plan, §5.2); the run is recorded
     * in the schema-runs ledger either way.
     *
     * @return SchemaRunRecord the recorded run (forensics: executed statements + outcomes)
     *
     * @throws MigrationFailedException on the first failing statement
     */
    public function apply(Plan $plan, ChangeClass $allow = ChangeClass::Safe): SchemaRunRecord
    {
        $this->installLedger();

        /** @var SchemaRunRecord */
        return $this->withLock(
            function () use ($plan, $allow): SchemaRunRecord {
                $runClass = $this->runRecordClass;
                $run = $runClass::newWith([
                    'fingerprint' => $plan->fingerprint,
                    'started_at'  => new \DateTimeImmutable(),
                ]);

                $outcomes = [];
                $failure = null;
                foreach ($plan->changes as $change) {
                    if (!$change->class->withinCeiling($allow)) {
                        continue;
                    }
                    foreach ($change->statements as $sql) {
                        try {
                            $this->connection->session->exec($sql);
                            $outcomes[] = self::outcome($change, $sql, true);
                        } catch (\Throwable $e) {
                            $outcomes[] = self::outcome($change, $sql, false, $e->getMessage());
                            $failure = new MigrationFailedException($change, $sql, $e);
                            break 2;
                        }
                    }
                }

                $run->statements_json = $outcomes;
                $run->finished_at = new \DateTimeImmutable();
                $run->error = $failure?->getMessage();
                Record::usingConnection($this->connection, static fn () => $run->save());

                if (null !== $failure) {
                    throw $failure;
                }

                return $run;
            },
        );
    }

    /**
     * Run-once data step (arch-migrations.md §6.2): executes `$step` at most once per database,
     * keyed by `$key`, recording the key in the step ledger. Returns true when the step ran, false
     * when its key had already run. The closure receives this migrator's `DbSession`.
     *
     * For data-shape transforms the schema differ cannot see (content changes within an unchanged
     * column type). Steps should be idempotent where the transform allows it.
     */
    public function dataStep(string $key, \Closure $step): bool
    {
        $this->installLedger();

        /** @var bool */
        return $this->withLock(
            function () use ($key, $step): bool {
                $stepClass = $this->stepRecordClass;
                $ran = Record::usingConnection(
                    $this->connection,
                    static fn (): ?SchemaStepRecord => $stepClass::findOne('step_key = ?', [$key]),
                );
                if (null !== $ran) {
                    return false;
                }

                $step($this->connection->session);

                $record = $stepClass::newWith(['step_key' => $key, 'applied_at' => new \DateTimeImmutable()]);
                Record::usingConnection($this->connection, static fn () => $record->save());

                return true;
            },
        );
    }

    /**
     * Canonical hash of the desired model set — store it (e.g. in a WP option) and skip even
     * `plan()`'s introspection while the running code's fingerprint matches the stored one.
     *
     * @param list<class-string<Record>> $recordClasses
     */
    public function fingerprint(array $recordClasses): string
    {
        // Sorted first, so the fingerprint is a function of the model *set* — passing the same
        // Records in a different order must not read as a schema change.
        return Fingerprint::of($this->connection->dialect, DependencyOrder::resolve($recordClasses)->classes);
    }

    /**
     * Advisory-lock wrapper. SQLite has no named advisory locks (GET_LOCK is MySQL-family) and
     * needs none — it serializes writers at the database level — so the lock is bypassed there.
     *
     * @param \Closure(): mixed $callback
     */
    private function withLock(\Closure $callback): mixed
    {
        if ($this->connection->dialect instanceof SqliteDialect) {
            return $callback();
        }

        return $this->connection->session->withAdvisoryLock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS, $callback);
    }

    /** Idempotently create the two ledger tables (they use the ambient attrecord table prefix). */
    private function installLedger(): void
    {
        if ($this->ledgerInstalled) {
            return;
        }
        foreach ([$this->runRecordClass, $this->stepRecordClass] as $class) {
            $ddl = $this->connection->dialect->buildCreateTable(TableSchema::fromClass($class), ifNotExists: true);
            foreach (explode(";\n", $ddl) as $statement) {
                if ('' !== trim($statement)) {
                    $this->connection->session->exec($statement);
                }
            }
        }
        $this->ledgerInstalled = true;
    }

    /** @return array<string, mixed> */
    private static function outcome(PlannedChange $change, string $sql, bool $ok, ?string $error = null): array
    {
        return array_filter([
            'table'   => $change->table,
            'kind'    => $change->kind,
            'subject' => $change->subject,
            'class'   => $change->class->value,
            'sql'     => $sql,
            'ok'      => $ok,
            'error'   => $error,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
