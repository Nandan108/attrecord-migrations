# attrecord-migrations

[![CI](https://github.com/Nandan108/attrecord-migrations/actions/workflows/ci.yml/badge.svg)](https://github.com/Nandan108/attrecord-migrations/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/nandan108/attrecord-migrations/branch/main/graph/badge.svg)](https://codecov.io/gh/nandan108/attrecord-migrations)
[![Packagist Version](https://img.shields.io/packagist/v/nandan108/attrecord-migrations)](https://packagist.org/packages/nandan108/attrecord-migrations)
[![PHP Version](https://img.shields.io/packagist/php-v/nandan108/attrecord-migrations)](https://packagist.org/packages/nandan108/attrecord-migrations)
[![License](https://img.shields.io/packagist/l/nandan108/attrecord-migrations)](LICENSE)

Declarative schema convergence for [attrecord](https://github.com/Nandan108/attrecord): the Record
class **is** the schema, so evolution is *convergence* — introspect the live database, diff it
against the attribute-derived `TableSchema`, and apply a classified, guarded `ALTER` plan. No
migration files, no second source of truth, no down scripts.

Design contract: [attrecord's `docs/arch-migrations.md`](https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md)
— read it for the full rationale (why convergence beats a migration chain for attribute-driven
schemas and file-replacement deployments, prior art, and the non-goals fence).

Backends: **MySQL/MariaDB, PostgreSQL, SQLite** — same tri-dialect matrix as attrecord itself.

---

## Installation

```bash
composer require nandan108/attrecord-migrations
```

Requires PHP 8.1+ and `nandan108/attrecord`. No other runtime dependencies.

It is a **separate package by design**: attrecord's DDL producer is fresh-install only, and
schema *evolution* carries risks (destructive ALTERs, live introspection, a safety model) that
have no business being reachable from a library you pull in to write rows. Nothing here runs
unless you call it.

---

## Quick start

```php
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;

$migrator = new SchemaMigrator($connection);      // an attrecord Connection

// plan() is PURE: reads the catalog, executes nothing. Always safe to call.
$plan = $migrator->plan([OrderRecord::class, SupplierRecord::class]);   // any order — see below

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

## Circular foreign keys

Two tables that reference each other have **no** creation order that works while every FK is
emitted inline — whichever goes first points at a table that does not exist yet. Rather than refuse
the model, one edge of each loop is deferred:

```
CREATE TABLE b (…)                     -- without its FK to a
CREATE TABLE a (… FOREIGN KEY → b)     -- b exists now
ALTER TABLE b ADD CONSTRAINT … FOREIGN KEY → a
```

Nothing to configure: the cycle is found in the declared graph, and which edge gets deferred falls
out of the input order, so the same model set always resolves the same way. The deferred `ADD` is
ordered after *every* create in the plan, because its target may be created later in the same run.

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

Both ledger tables can live under your own naming — subclass the Record with its own `#[Table]`
and hand the subclass to the migrator:

```php
#[Table(name: 'myapp_schema_runs')]
final class MyRunRecord extends SchemaRunRecord {}

$migrator = new SchemaMigrator($connection, runRecordClass: MyRunRecord::class);
```

## v0.1 limitations (all fail LOUD as `Manual`, never silently wrong)

- **SQLite**: no in-place column modification and no FK add/drop — those changes classify Manual
  (the table-rebuild dance is phase 2). `ADD COLUMN` with a non-constant default likewise. This
  also means a **cyclic** schema only partly converges there: the tables are created, but the
  deferred constraint (see "Circular foreign keys" above) cannot be added and is reported as Manual.
- **Enum members** are only introspectable on MySQL-family (PG/SQLite store them in CHECK
  constraints, not modeled yet) — member drift is caught on MySQL, invisible elsewhere.
- **MySQL-family `json`** folds to `longtext` (MariaDB stores JSON as LONGTEXT) — json↔longtext
  drift is undetectable there.
- **Generated columns** are compared on every facet *except* nullability and the expression
  itself — both of which the engine owns rather than the declaration (it reports them nullable
  whatever you wrote, and stores its own spelling of the expression). Comparing either would make
  a correct database report drift forever. The cost: a *changed* generation expression is not
  detected. A column gaining or losing generation entirely still is.
- `ON UPDATE CURRENT_TIMESTAMP` drift and PostgreSQL `BIT` round-trips are not compared.
- Tables the Records don't declare are **invisible** — never dropped, never touched.
- An index whose leading columns are a foreign key's columns is treated as that FK's supporting
  plumbing and never proposed for dropping, even if the Records don't declare it. A genuinely
  operator-added index on exactly an FK's columns therefore survives — the fail-safe direction.

---

## Running tests

```bash
# Unit tests (no DB needed) — differ/classifier against hand-built live schemas
composer test -- --testsuite unit

# Integration tests. The MariaDB + PostgreSQL containers are attrecord's; SQLite needs no server.
docker compose up -d          # in ../attrecord
composer test -- --testsuite integration

# All tests
composer test

# One backend only (the integration suites are tagged by @group)
composer test -- --testsuite integration --group Mysql
composer test -- --testsuite integration --group Pgsql
composer test -- --testsuite integration --group Sqlite
```

Integration tests reuse attrecord's containers but their **own database**
(`attrecord_migrations_test`, auto-created by the test support layer) — never attrecord's
`attrecord_test`. Environment variables (defaults shown):

```
# MySQL / MariaDB
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=attrecord_migrations_test
DB_USER=root
DB_PASS=root

# PostgreSQL
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DB=attrecord_migrations_test
PGSQL_USER=postgres
PGSQL_PASS=postgres
```

An unreachable engine makes its suite **skip**, which locally is a convenience and in CI would be
a lie — so CI runs with `--fail-on-skipped`. A skipped backend is not a passing backend.

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

### Code style & static analysis

Style is enforced with [PHP CS Fixer](https://cs.fixer.dev/) (the `@Symfony` ruleset plus project
overrides in `.php-cs-fixer.php`), and types with [Psalm](https://psalm.dev/) at level 1:

```bash
composer cs-fix     # apply PHP CS Fixer
composer cs-check   # report violations without changing files (used in CI)
composer psalm      # static analysis — must be zero errors
```

All three run in CI against PHP 8.1–8.4 with MySQL 8.0/8.4, MariaDB 10.11/11.4, PostgreSQL 14–17
and SQLite — the same published attrecord release a consumer would get.

---

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the dev setup
and the checks to run. Behavioural design changes belong in the design contract
([attrecord's `docs/arch-migrations.md`](https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md)),
whose §7 is the non-goals fence.

---

## License

[MIT](LICENSE) © Samuel de Rougemont
