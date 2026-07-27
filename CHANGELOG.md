# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Creation order is derived, not demanded.** `plan()` no longer expects FK-ordered input: the
  order comes from the declared `#[ForeignKey]` graph (`DependencyOrder`). Self-references and
  targets outside the model set are not dependencies — the latter being unmanaged, hence
  pre-existing rather than missing. `fingerprint()` sorts too, so it is a function of the model
  *set* and no longer changes when the same Records are passed in a different order.
- **Circular foreign keys converge.** A cycle has no valid order with every FK inline, so one edge
  per loop is deferred: its table is created without that constraint (attrecord's new
  `buildCreateTable(omitForeignKeys:)`) and the constraint is added after every create. Which edge
  is deferred is deterministic. On SQLite, which cannot add an FK to an existing table, the
  deferred constraint reports as `Manual` instead — pinned per backend by the test suite.
- **Ledger table names are configurable.** `SchemaRunRecord` / `SchemaStepRecord` are subclassable
  (`#[Table(name:)]` on the subclass, columns inherited) and `SchemaMigrator::__construct` accepts
  `runRecordClass` / `stepRecordClass`, so a host project can keep its ledger under its own naming
  instead of the generic `attrecord_schema_*`.

### Changed

- Requires `nandan108/attrecord: ^0.12` for the `omitForeignKeys` seam.

## [0.1.0] - 2026-07-27

Initial release — the full declarative-convergence pipeline from
[attrecord's arch-migrations.md](https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md),
on MySQL/MariaDB, PostgreSQL and SQLite:

- **Introspection** (`Live*` model + per-dialect `SchemaIntrospector`s): `information_schema`
  (MySQL, scoped to `DATABASE()`), `information_schema` + `pg_catalog` (PG, scoped to
  `current_schema()`), `PRAGMA` (SQLite, incl. recovery of declared UNIQUE-constraint and FK names
  from `sqlite_master.sql`).
- **Normalization** (`ColumnTuple` + per-dialect `ColumnNormalizer`s) — the correctness core: both
  diff sides reduce to one dialect-local canonical tuple; anything unsure degrades to Manual.
  Owns the cross-engine quirks: MariaDB quoted defaults / `'NULL'` strings / JSON-as-LONGTEXT,
  PG `::casts` / SERIAL / bare-TIMESTAMP≡(6) / unsigned collapse, SQLite type affinity.
- **Diff + classification** (`SchemaDiffer`, `Plan`/`PlannedChange`/`ChangeClass`): Safe /
  Destructive / Manual ceiling model, `mayRejectExistingRows` flag, declared renames via
  `#[Column(renamedFrom:)]` (never inferred), and recognition of an FK's supporting index by
  **shape** — an engine-created index outlives the constraint it was named after, and can still be
  required by another FK on the same column.
- **ALTER emission** (per-dialect `AlterEmitter`s) built on attrecord's public DDL fragment seams —
  one rendering authority for CREATE and ALTER.
- **`SchemaMigrator`** facade: pure `plan()` (+ model-set fingerprint), advisory-locked ceiling-
  filtered `apply()` with per-statement execution and a forensic run ledger
  (`attrecord_schema_runs`), run-once `dataStep()` registry (`attrecord_schema_steps`),
  `fingerprint()` fast path.

Verified on real engines rather than on hand-built fixtures: a **golden round-trip** over every
portable `ColumnType` (plus `SET`/`BIT` on MySQL) and a **drift matrix** running
converge → inject drift → plan → apply → re-plan-empty for each kind of change, with per-backend
expectations so undetectable drift is pinned as explicitly empty.

Requires attrecord with the schema-evolution seams (`buildColumnLine` / `buildForeignKeyLine` /
`renderColumnType` on `SqlDialect`, `#[Column(renamedFrom:)]`).
