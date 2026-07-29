# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-07-29

The other half of attrecord 0.14.0's composite primary keys. A table keyed on two columns can now
be declared *and* compared, which together are what let it into a managed schema at all — declaring
it without comparing it would have reported drift forever.

### Changed

- **Requires `nandan108/attrecord` ^0.14** (was ^0.13), for `#[PrimaryKey(columns: …)]` and
  `TableSchema::pkColumns()`.

### Added

- **Composite primary keys are compared.** The migrations half of composite-PK support; attrecord's
  `#[PrimaryKey(columns: […])]` is the other, and is inert without this one. The differ compared
  the live key against `[$desired->pk]` — a single-element list — so a table declaring a composite
  key could not match its own live definition on any engine, and would have reported `Manual` drift
  forever from the moment it was created. It now compares `TableSchema::pkColumns()`, the whole key.

  Listed as *added* rather than *fixed*: the desired side can only be composite once attrecord's
  new attribute exists, so no released combination could reach the old behaviour. The line was
  incomplete for a shape that only just became declarable, not broken.

  The incompleteness ran both ways, and both are pinned per backend: a composite table converges
  and re-plans empty, and a key *narrowed* to the first member is still reported — the second
  mattering because such a key would have compared **equal** under the old comparison, hiding real
  drift while flagging correct tables.

  What it unlocks: tables of this shape previously needed hand-written DDL, and hand-written DDL is
  invisible here — the differ compares the live database against *declared* schemas — so they sat
  outside the managed set and drifted unobserved.

  A *changed* key remains `Manual` by design: rebuilding a primary key rewrites the clustered index
  and can fail on duplicate rows. Composite keys became comparable, not alterable.

  Requires an attrecord providing `#[PrimaryKey(columns: …)]` and `TableSchema::pkColumns()`.

## [0.3.0] - 2026-07-29

One bug, and it was the worst kind this package can have: on PostgreSQL and SQLite the plan came
back **empty** while the database was rejecting writes. A tool whose entire promise is "an empty
plan means the schema matches" cannot afford a case where it does not, so this is a minor bump
rather than a patch despite being a single fix.

### Changed

- **Requires `nandan108/attrecord` ^0.13** (was ^0.12), for
  `ColumnDefinition::enumCheckConstraintName()` — the shared definition of the constraint name that
  makes enum members findable on both the introspect and the emit side.

### Fixed

- **Enum member drift is no longer invisible on PostgreSQL and SQLite.** Neither has a native ENUM
  type, so the producer stores the member list in a CHECK constraint — which this package did not
  read. Both sides normalized to `members: null`, `null !== null` is false, and the differ
  concluded there was no drift. The failure that produced: add a case to a PHP backed enum, run
  convergence against PostgreSQL, get an **empty plan** — schema reports healthy — and then watch
  the first `INSERT` carrying the new value die on a constraint violation. Silent at migration
  time, loud at runtime, and reported clean by the one tool whose job was to say otherwise. MySQL
  never had this: its members are legible in the column type.

  Members are now recovered from the constraint body by `EnumCheckParser`. Detection works on all
  three backends; **PostgreSQL also applies** the change (dropping and re-adding the constraint in
  one statement, since PG has no "alter constraint body"), while **SQLite detects but classifies
  Manual** — it has no `DROP CONSTRAINT`, so widening needs the 12-step table rebuild. Growth stays
  Safe on both, member removal Destructive, unchanged from the MySQL rule.

  Parsing is deliberately narrow: it recovers the shapes an engine produces from the *one*
  expression the producer emits, not CHECK expressions in general. PostgreSQL alone rewrites
  `col IN ('a','b')` four different ways depending on column type and member count — collapsing a
  single member to `col = 'x'::text`, double-casting on VARCHAR — and a general expression differ
  would have to reconcile all of that against the declared text, re-planning forever. That is the
  trap generated columns already fell into. Every rendering in `EnumCheckParserTest` was captured
  from a live PostgreSQL 16 / SQLite 3, not written from the documentation, because three of the
  four are not what the documentation would lead you to expect.

  An unparseable body yields "members unknown", and an unknown on **either** side now skips the
  facet instead of diffing it. Without that, an unreadable constraint would plan a swap whose
  result is still unreadable — a plan that never converges, breaking the invariant that a freshly
  created table re-plans empty.

  The drift matrix had `enum_member_append` pinned as "not representable on this engine" for both
  backends. That pin was the blind spot, faithfully recorded and shipped; both now assert real
  outcomes.

  Requires attrecord >= 0.13, which names the constraint `chk_<column>_enum`. The name is the whole
  mechanism: PostgreSQL rewrites the *body* but leaves the name alone, so it is the only stable
  handle on which constraint holds the members. PostgreSQL's auto-name for an anonymous column
  CHECK (`<table>_<column>_check`) is also dropped when swapping, so tables created before the
  constraint was named still converge.

## [0.2.0] - 2026-07-27

Everything here came out of dogfooding the pipeline against a real schema (InvFlux's, ~58 tables)
rather than against fixtures. Two features the design had not anticipated, two bugs the test suite
could not have produced, and one API change that fell out of both.

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
- **`plan()` / `fingerprint()` accept a ready-made `TableSchema`** alongside Record class-strings,
  for a table whose shape is only known at runtime — derive one with attrecord's
  `TableSchema::extendedWith()`. Those columns are then created, converged, diffed and fingerprinted
  like any other, instead of living in a hand-written `ALTER` that no tooling can see. Ordering is
  keyed by table name rather than by class as a result: a schema need not come from a class, and
  the table is the thing being created. (`DependencyOrder::resolve()` now takes and returns
  `TableSchema`; `sort()` keeps its class-in/class-out shape.)
- **`PartiallyDeclared`**: a Record may declare only part of its table. Implementing the interface
  narrows the differ to what is declared — missing declared columns and indexes are still added
  and declared ones still converge, but nothing live-but-undeclared is proposed for dropping. For
  tables whose shape is partly computed at runtime (a column per registered dimension, a plugin's
  extension columns), where the default "undeclared means drift" rule is actively wrong. Opt-in
  per Record, because the trade-off runs one way: a genuinely stray column is not surfaced either.
- **Ledger table names are configurable.** `SchemaRunRecord` / `SchemaStepRecord` are subclassable
  (`#[Table(name:)]` on the subclass, columns inherited) and `SchemaMigrator::__construct` accepts
  `runRecordClass` / `stepRecordClass`, so a host project can keep its ledger under its own naming
  instead of the generic `attrecord_schema_*`.

### Fixed

- **A numerically-named constraint no longer makes a table uninspectable.** PHP coerces a
  numeric-string array key to `int`, so a constraint or index named `1` — which is how MariaDB
  names an unnamed `FOREIGN KEY` — came back out of the introspector's accumulators as an int and
  hit a string-typed constructor: `TypeError`, on a table that was in no way malformed. Any
  pre-existing table with a hand-written unnamed FK was affected. The key types are now declared
  `array-key` (which is what PHP actually guarantees) rather than `string`, and re-cast at each
  point of use; correcting the annotation is what let Psalm find the four further places the same
  coercion reached. Found by running against a real WordPress database.
- **Generated columns no longer report phantom drift.** A freshly created table containing a
  generated column re-planned non-empty forever: engines report such a column as nullable
  regardless of its declaration, and store their own rewriting of the expression
  (`(a IS NULL AND b IS NULL)` → `` `a` is null and `b` is null ``). Both facets are now skipped
  when *both* sides are generated — every other facet still compares, and a column gaining or
  losing generation is still drift. Found by dogfooding against a real schema; the golden
  round-trip had no generated-column fixture.

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

[0.2.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Nandan108/attrecord-migrations/releases/tag/v0.1.0
