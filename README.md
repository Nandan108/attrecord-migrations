# attrecord-migrations

Declarative schema convergence for [attrecord](https://github.com/Nandan108/attrecord): the Record
class **is** the schema, so evolution is *convergence* — introspect the live database, diff it
against the attribute-derived `TableSchema`, and apply a classified, guarded `ALTER` plan. No
migration files, no second source of truth, no down scripts.

Design contract: [attrecord's `docs/arch-migrations.md`](https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md)
— read it for the full rationale (why convergence beats a migration chain for attribute-driven
schemas and file-replacement deployments, prior art, and the non-goals fence).

Backends: **MySQL/MariaDB, PostgreSQL, SQLite** — same tri-dialect matrix as attrecord itself.

## Quick start

```php
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;

$migrator = new SchemaMigrator($connection);      // an attrecord Connection

// plan() is PURE: reads the catalog, executes nothing. Always safe to call.
$plan = $migrator->plan([SupplierRecord::class, OrderRecord::class /* FK parents first */]);

$plan->isEmpty();                 // fast path — nothing to do
foreach ($plan->changes as $c) {  // inspectable: SQL + classification + reason
    printf("[%s] %s %s.%s — %s\n", $c->class->value, $c->kind, $c->table, $c->subject, $c->reason);
}

$migrator->apply($plan);                                   // Safe changes only (default)
$migrator->apply($plan, allow: ChangeClass::Destructive);  // opt-in escalation
```

A converged database re-plans **empty** — that invariant (create → introspect → identical
canonical tuples on both sides) is pinned by the test suite on all three backends.

## Change classes — the safety model

Every planned change carries a class; `apply(allow:)` is a **ceiling**:

| Class | Applied | Examples |
| --- | --- | --- |
| `Safe` (default) | yes | `ADD COLUMN` (nullable/defaulted), `ADD INDEX`, widenings (`VARCHAR(64)→(191)`, `SMALLINT→INT`), default changes, declared renames. `ADD UNIQUE`/`ADD FK` are Safe but flagged `mayRejectExistingRows` — they can *loudly* reject (atomic failure, never silent loss). |
| `Destructive` | opt-in only | `DROP COLUMN`, narrowing conversions, `NULL→NOT NULL` tightening, undeclared-index drops. |
| `Manual` | **never** | PK changes, auto-increment/generation drift, anything the pipeline is *unsure* about, SQLite rebuild-only changes. No SQL — a reason to read, not a statement to run. |

The pipeline's bias is **fail-safe**: an unparseable live type or ambiguous facet degrades to
Manual with a reason. It never guesses an ALTER (the Doctrine `schema-tool:update` lesson —
normalization + classification are the load-bearing parts, not the diff).

## Renames are declared, never inferred

```php
#[Column(ColumnType::VarChar, length: 64, renamedFrom: 'sku_code')]
public string $sku = '';
```

produces a data-preserving `RENAME`/`CHANGE COLUMN` instead of a destructive drop+add. Inference
from drop+add similarity is a known trap (Skeema refuses it; Django prompts a human); the
`renamedFrom` marker is permanent, cheap documentation of the column's history.

## Run-once data steps

Schema converges because its state is introspectable. Data *shape* is not — so transforms the
differ cannot see (content changes within an unchanged column type) use the run-once registry:

```php
$migrator->dataStep('2026-07-wrap-payload-json', function ($session): void {
    $session->exec("UPDATE orders SET payload = JSON_OBJECT('data', payload) WHERE …");
});
```

At most once per database, recorded in the step ledger (`attrecord_schema_steps`) — the one place
the ledger is authoritative. There is no `down()`: on a file-replacement deployment model no code
exists to run it at the right moment; roll forward or restore a backup.

## Fingerprint fast path

```php
$fp = $migrator->fingerprint($classes);   // == $plan->fingerprint
```

sha256 over the dialect's own `buildCreateTable()` output. Store the last-converged value (e.g. a
WordPress option) and skip even `plan()`'s introspection while the running code's fingerprint
matches.

## The ledger

Every `apply()` run is recorded in `attrecord_schema_runs` (statements + outcomes + error) — for
**forensics only**. The differ never reads it: truth about the live schema comes from the live
schema, so a restored backup or hand-edited database simply re-plans correctly.

## v0.1 limitations (all fail LOUD as `Manual`, never silently wrong)

- **SQLite**: no in-place column modification and no FK add/drop — those changes classify Manual
  (the table-rebuild dance is phase 2). `ADD COLUMN` with a non-constant default likewise.
- **Enum members** are only introspectable on MySQL-family (PG/SQLite store them in CHECK
  constraints, not modeled yet) — member drift is caught on MySQL, invisible elsewhere.
- **MySQL-family `json`** folds to `longtext` (MariaDB stores JSON as LONGTEXT) — json↔longtext
  drift is undetectable there.
- `ON UPDATE CURRENT_TIMESTAMP` drift and PostgreSQL `BIT` round-trips are not compared.
- Tables the Records don't declare are **invisible** — never dropped, never touched.
- An index whose leading columns are a foreign key's columns is treated as that FK's supporting
  plumbing and never proposed for dropping, even if the Records don't declare it. A genuinely
  operator-added index on exactly an FK's columns therefore survives — the fail-safe direction.

## Development

Tri-backend Definition of Done, mirroring attrecord's: `composer test` must be green on
MySQL/MariaDB + PostgreSQL + SQLite (a skipped backend is not a passing backend), psalm level 1
clean, cs-fix applied. See `CLAUDE.md`. attrecord is consumed via a composer path repository in
development.

Two suites carry most of the weight, both against real engines:

- **Golden round-trip** — every portable `ColumnType` (plus `SET`/`BIT` on MySQL, where they exist)
  created by the DDL producer, introspected back, and asserted to normalize to *identical* tuples
  on both sides. A type missing here is a type that could false-positive an ALTER at a consumer.
- **Drift matrix** — one scenario per kind of drift (widen/narrow, nullability, default, integer
  and decimal widening, enum members, rename, index reshape, FK action change, undeclared FK),
  injected as raw DDL into a converged database, then `plan → assert kind + class → apply →
  re-plan EMPTY`. Each backend states its own expectations, so the drifts an engine *cannot see*
  (SQLite stores affinity, not width; enum members are MySQL-only) are pinned as explicitly empty
  rather than quietly untested.
