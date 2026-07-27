# Contributing to attrecord-migrations

Thanks for your interest in improving attrecord-migrations! This package applies `ALTER` statements
to live databases, so the contribution loop has one extra rule beyond the usual: **a change that
makes the pipeline guess is not an improvement.** See "Safety" below.

## Requirements

- PHP 8.1+ (CI runs 8.1–8.4)
- [Composer](https://getcomposer.org/)
- Docker — for the MySQL/MariaDB and PostgreSQL integration databases. SQLite runs in-process and
  unit tests need no database at all.

## Getting started

attrecord is consumed through a composer **path repository** (`../attrecord`), so clone the two
side by side:

```bash
git clone https://github.com/Nandan108/attrecord.git
git clone https://github.com/Nandan108/attrecord-migrations.git
cd attrecord-migrations
composer install
```

The database containers are attrecord's (`docker compose up -d` in `../attrecord`); this package
uses its own database on those servers, `attrecord_migrations_test`, created automatically by the
test support layer.

## Running the checks

The same checks run in CI; please make sure all pass before opening a PR.

```bash
# Unit tests (no database needed)
composer test -- --testsuite unit

# Integration tests against MySQL/MariaDB + PostgreSQL + SQLite
docker compose up -d          # in ../attrecord
composer test                 # unit + integration, all three backends

# Static analysis (must be zero errors)
composer psalm

# Code style — PHP CS Fixer
composer cs-check             # report violations (CI uses this)
composer cs-fix               # apply fixes locally
```

Connection settings default to attrecord's `docker-compose.yml` services; override via the `DB_*` /
`PGSQL_*` environment variables documented in the README. An unreachable engine makes its suite
skip, which is fine locally and fatal in CI (`--fail-on-skipped`) — **a skipped backend is not a
passing backend**, and "green" on a suite that never ran is the failure mode most worth preventing
in a package that writes DDL.

## Safety — the invariants a PR must not weaken

1. `plan()` is **pure**: it reads the catalog and executes nothing. Always safe to call.
2. `apply(allow:)` is a **ceiling**. `Safe` applies by default, `Destructive` only on explicit
   opt-in, and **`Manual` is never auto-applied** — it carries a reason, never SQL.
3. **Fail-safe bias**: a normalizer or differ that is unsure classifies `Manual`. Never guess an
   `ALTER`. Widening the vocabulary is welcome; guessing past its edge is not.
4. **Renames are declared, never inferred** (`#[Column(renamedFrom:)]`). Inferring one from
   drop+add similarity is how a column's data disappears.
5. Objects the Records do not declare are **invisible** — never dropped, never touched.

## Coding standards

- **Style:** PHP CS Fixer with the `@Symfony` ruleset plus project overrides (`.php-cs-fixer.php`).
  Run `composer cs-fix` before committing.
- **Static analysis:** Psalm at level 1 — keep it clean. Prefer precise type annotations over
  `@psalm-suppress`; reserve suppressions for what the type system genuinely can't express.
- **Tests:** new behaviour needs coverage, and where it goes matters:
  - Behaviour that must hold on every engine belongs in a shared `…Cases` trait under
    `tests/Integration/Cases/`, so it runs on MySQL, PostgreSQL **and** SQLite.
  - A **new column type** goes into `tests/Fixtures/TypeMatrixRecord.php` (or the MySQL-only
    fixture if it exists on only one engine). The golden round-trip then proves both sides
    normalize identically, on every backend, with no extra test code.
  - A **new kind of drift** goes into `DriftMatrixCases` as a scenario, with an entry in *every*
    backend's `driftMatrix()` — including the engines that cannot see it, where the expectation is
    an explicitly empty plan. A missing entry fails rather than silently skips.
  - Dialect-specific SQL *shapes* belong in the unit differ tests.
- **Commits:** conventional-commit style (`feat:`, `fix:`, `docs:`, `test:`, …) with a clear body.

## Scope

The design contract is [attrecord's `docs/arch-migrations.md`](https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md);
**§7 (Non-goals) is the fence** — read it before building anything large, and open an issue to
discuss first. Two boundaries worth stating up front: this package converges *declared* schema, so
there are no migration files and no `down()` scripts (roll forward, or restore a backup); and it
never writes through core seams that don't exist — if one is missing, it is added to attrecord
first, with its own tests, then consumed here.

## Reporting bugs

`plan()` is pure and inspectable, which makes for an unusually good bug report. Please include:

- the Record definition(s) involved,
- the engine and version (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, SQLite …),
- the live table as the engine describes it (`SHOW CREATE TABLE`, `\d+`, or
  `SELECT sql FROM sqlite_master`),
- and the planned changes — kind, class, and reason:

```php
foreach ($migrator->plan($classes)->changes as $c) {
    printf("[%s] %s %s.%s — %s\n", $c->class->value, $c->kind, $c->table, $c->subject, $c->reason);
}
```

A **false positive** (a converged database that still plans a change) is the highest-value report
this package can receive: it means two sides of the diff normalize differently, which is a
normalizer bug and would eventually hit someone as a needless ALTER.
