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

Requires PHP 8.1+ and `nandan108/attrecord` `^0.15 || ^0.16`. No other runtime dependencies.

Footprint: `src/` is ~143 KB across 33 files (~89 KB of that is code — the other 38% is
docblocks), so the published package is **~64 KB zipped**. That is on top of attrecord itself, and
only for consumers who want schema convergence: the split exists so writing rows never drags in
the machinery that rewrites schemas.

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
$migrator->apply($plan, allow: ChangeClass::Assisted);     // everything with a statement
```

A converged database re-plans **empty** — that invariant (create → introspect → identical
canonical tuples on both sides) is pinned by the test suite on all three backends.

## Change classes — the safety model

Every planned change carries a class; `apply(allow:)` is a **ceiling** over the ladder
`Safe → Destructive → Assisted`, each admitting everything at or below it:

| Class | Applied | Examples |
| --- | --- | --- |
| `Safe` (default) | yes | `ADD COLUMN` (nullable/defaulted), `ADD INDEX`, widenings (`VARCHAR(64)→(191)`, `SMALLINT→INT`), default changes, declared renames. `ADD UNIQUE`/`ADD FK` are Safe but flagged `mayRejectExistingRows` — they can *loudly* reject (atomic failure, never silent loss). |
| `Destructive` | opt-in only | `DROP COLUMN`, narrowing conversions, `NULL→NOT NULL` tightening, undeclared-index drops. |
| `Assisted` | opt-in, its own ceiling | A changed generation expression. The statement is known and carried; what it needs is a person who read it and said yes. **Not** reached by opting into `Destructive` — a widened destructive policy must not sweep in changes chosen deliberately. |
| `Manual` | **never** | PK changes, auto-increment drift, anything the pipeline is *unsure* about, SQLite rebuild-only changes. No SQL — a reason to read, not a statement to run, which is why no ceiling admits it. |

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

## Tables whose shape is computed

Some tables have a shape that is only known at runtime: a registry that grows a column per
registered dimension, an extension table a plugin writes into. A Record class cannot describe
those, so `plan()` also accepts a ready-made `TableSchema` — derive one with attrecord's
`TableSchema::extendedWith()` and pass it alongside your class-strings:

```php
$schema = TableSchema::fromClass(SlotSpace::class)->extendedWith(
    columns: ['dim_loc' => new ColumnDefinition(name: 'dim_loc', /* … */)],
    indexes: ['idx_dim_loc' => ['active', 'dim_loc']],
);

$migrator->plan([Order::class, Supplier::class, $schema]);
```

Those columns are then created, converged and diffed like any other — including being **added** to
an existing table when the runtime set grows. `fingerprint()` covers them too, so the fast path
notices when the runtime set changes. Describing them beats the alternative, a hand-written
`ALTER TABLE` run at boot: a second source of DDL that no tooling can see or verify.

### When you can't describe them: `PartiallyDeclared`

If the extra columns genuinely cannot be enumerated, the Record can opt out of drift detection for
whatever it does not declare:

```php
#[Table(name: 'app_slotspace')]
final class SlotSpace extends Record implements PartiallyDeclared {}
```

The differ is then narrowed to what the Record declares: missing declared columns and indexes are
still added, declared ones still converge, but nothing undeclared is ever proposed for dropping —
columns, indexes and constraints alike. The trade-off is one-directional, which is why it is
opt-in per Record: on such a table, a genuinely stray column from an old version is never
surfaced either. Prefer describing the columns when you can; this is the fallback.

(The two do not combine: a `TableSchema` you built is taken at face value, since the point of
building it is that the columns are now described.)

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

## Limitations (all fail LOUD as `Manual` or `Assisted`, never silently wrong)

- **SQLite**: no in-place column modification and no FK add/drop — those changes classify Manual
  (the table-rebuild dance is phase 2). `ADD COLUMN` with a non-constant default likewise. This
  also means a **cyclic** schema only partly converges there: the tables are created, but the
  deferred constraint (see "Circular foreign keys" above) cannot be added and is reported as Manual.
- **Enum members** are visible on all three backends. MySQL-family carries them in the column
  type; PostgreSQL and SQLite have no native ENUM, so the producer stores them in a
  `chk_<column>_enum` CHECK constraint and they are read back out of it. PostgreSQL applies a
  member change by swapping that constraint; **SQLite detects but cannot apply it** (no
  `DROP CONSTRAINT` — it needs the table rebuild), so it classifies Manual. A constraint body this
  package cannot parse yields "members unknown", and an unknown on either side skips the
  comparison rather than guessing — the fail-safe direction, and the one that keeps an unparseable
  body from planning a swap forever.
- **Primary keys are compared but never altered.** A key that differs from the declaration —
  composite or not — is reported `Manual` with both sides named, because rebuilding a primary key
  rewrites the clustered index and can fail outright on duplicate rows. Composite keys are
  *comparable* (a `#[PrimaryKey(columns: …)]` table converges from empty and re-plans empty on all
  three backends), not alterable.
- **MySQL-family `json`** folds to `longtext` (MariaDB stores JSON as LONGTEXT) — json↔longtext
  drift is undetectable there.
- **Generated columns** are compared on every facet except **nullability**, which the engine owns
  outright — it reports the column nullable whatever you wrote, so comparing it would make a
  correct database report drift forever.

  The **expression** is compared, against the engine's own re-printing of it rather than your
  spelling: identifier quoting and whitespace are dropped, and bracket pairs are removed where no
  operator can bind across them (MariaDB drops the outer pair you wrote, MySQL adds one around a
  compound function argument — neither means anything). Brackets that group are kept and compared.
  A difference the canon cannot absorb is reported `Assisted` with *both* spellings quoted, so a
  normalization gap looks like one at a glance instead of like a schema change; adopting it is
  harmless either way. A column gaining or losing generation entirely is a plain modification.
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
  (SQLite stores affinity, not width) are pinned as explicitly empty rather than quietly untested.
  The enum-member scenario is why that matters: it sat pinned as empty on PG and SQLite for a
  release, which is exactly how long the blind spot went unnoticed — the expectations now say
  `modify_column`/Safe and `manual`/Manual.

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
