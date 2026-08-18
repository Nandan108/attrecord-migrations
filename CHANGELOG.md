# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-08-18

**CHECK constraints converge.** attrecord 0.17 lets a Record declare one with `#[Check]`; this
release detects a missing or undeclared one on a live table and emits the `ALTER` for it.

**Requires attrecord `^0.17.1`** (the `#[Check]` attribute, `TableSchema::$checks`, and
`SqlDialect::buildCheckLine()`). The previous range is disjoint under Composer's caret, so bump both
in one pass.

### Added

- **`add_check` / `drop_check`.** A declared constraint missing live is added; a live one nobody
  declares is dropped. `AlterEmitter` grows `addCheck()` / `dropCheck()`, both returning null on
  SQLite, where there is no `ADD`/`DROP CONSTRAINT` and the whole table must be rebuilt — Manual,
  pinned as an expectation rather than skipped.

  `DROP CONSTRAINT` rather than MySQL's `DROP CHECK`, verified accepted by MySQL 8.0 and MariaDB 11.8
  alike; `DROP CHECK` is MySQL-only.

- **`LiveTable::$checks`** — name → body, a faithful mirror including the constraints the *engine*
  owns. Read from `information_schema` on MySQL/MariaDB, `pg_constraint` on PostgreSQL, and the
  stored `CREATE` text on SQLite (which has no constraint catalogue; the same scan already serving
  enum members now returns every named CHECK).

  MySQL and MariaDB need different queries for the same information, and the difference is a
  consequence of the scoping: MariaDB's `CHECK_CONSTRAINTS` carries `TABLE_NAME`, MySQL's does not —
  its names being unique per schema, it considers the table redundant — so MySQL joins through
  `TABLE_CONSTRAINTS`. MariaDB's shape is tried first, because the fallback query is valid on both
  and probing the other way round would cross-join two same-named constraints on MariaDB.

### Adding a constraint is Safe, and flagged

`add_check` is `ChangeClass::Safe` with `mayRejectExistingRows`, the same treatment as `ADD UNIQUE`
on possibly-duplicate data and `ADD FOREIGN KEY` on possible orphans: the statement validates every
row already in the table and either succeeds or fails whole. It never half-applies and never
silently discards the rows that break the rule — which is exactly why it can be offered as Safe
rather than held back. Dropping an undeclared constraint is `Destructive`, like any other drop.

### Converged by name, deliberately never by expression

No engine stores the expression as written. MySQL re-prints it with charset introducers and its own
brackets, PostgreSQL adds `::text` casts. A body comparison therefore cannot distinguish *the author
changed the rule* from *the engine spells it differently*, and both answers to that ambiguity are
wrong in a different direction: compare, and a correct database reports drift forever; skip, and a
corrected rule never reaches a database that has the old one. Generated-column expressions
demonstrated both failure modes, in that order, and 0.5.2 was the second one.

The producer removes the dilemma instead of resolving it: an attrecord CHECK name carries a digest of
its expression, so an edited rule *is* a differently-named constraint and appears here as one add and
one drop. Name-only diffing is therefore complete for constraints attrecord emitted. The honest
limit, documented as one: a **hand-written** constraint whose body is edited in place, name
unchanged, is invisible.

### Two constraints that belong to a column, and are never dropped

- The `chk_<column>_enum` member list the producer writes on PostgreSQL and SQLite. The column diff
  converges those; dropping one here would take the enum's enforcement with it.
- **MariaDB's `CHECK (json_valid(col))`.** MariaDB has no JSON storage type — a `JSON` column is
  LONGTEXT plus that constraint, created by the engine, named after the column, declared by nobody.
  A name-only diff reads it as undeclared, so without this every table with a JSON column would plan
  a drop and never converge. Found by the existing suite the moment checks were introspected at all.
  Matched on the body as well as the name, so an author's own constraint that happens to carry a
  column's name stays theirs.

## [0.5.2] - 2026-08-16

A changed generation expression was invisible, and the one class that could have reported it could
never be applied. Both halves are addressed: it is now detected, and it is now applicable by someone
who chooses to.

### Added

- **`ChangeClass::Assisted` — the statement is known, but only a person runs it.** `Manual` was two
  things wearing one label: changes with *no safe statement* (a changed primary key, a SQLite column
  rebuild) and changes whose statement is perfectly well known but too consequential to run
  unattended. Only the second kind can be offered to an operator as "here is the exact SQL, apply
  it", and conflating them meant it could not be — `Manual` carries no statements, so there was
  nothing to run.

  The ceiling is now a ladder, `Safe → Destructive → Assisted`, each admitting everything at or
  below it. `Manual` is off the ladder rather than at the top: `withinCeiling()` returns false for
  it against *every* ceiling, because there is no SQL to authorise. And `Assisted` is deliberately
  **not** reached by opting into `Destructive` — widening a destructive policy must not sweep in
  changes whose whole point is that somebody chose them.

  `Plan::hasAssisted()` joins `hasDestructive()`/`hasManual()`, and `hasBeyondSafe()` accounts for
  it — an Assisted-only plan previously reported nothing beyond Safe.

### Fixed

- **A changed generation expression is detected again, and is `Assisted`.** The `generated` facet
  was skipped whenever both sides were generated columns, a documented fail-safe: engines store
  their own rewriting of what you wrote, so a textual comparison drifted against a table that was in
  fact exactly as declared.

  The price only came due later. A corrected expression produced **no planned change at all**, so
  the repair reached new installs and no existing one — silently, indefinitely, with the old column
  left in place on every upgraded site. That was found downstream the hard way: an unsigned
  generated column whose arithmetic could underflow left its table permanently un-`ALTER`-able, and
  fixing the declaration changed nothing on any database that already had it.

  The expression is now compared, and the comparison absorbs the bracketing each engine adds or
  drops when it re-prints what you declared. MariaDB discards a redundant outer pair, so
  `(closed_at IS NULL)` reads back as `closed_at is null`; MySQL adds one around a compound function
  argument, so `GREATEST(0, a - b)` reads back as `greatest(0,(a - b))`. `looseExpr()` removes a
  bracket pair only where **no operator can bind across it** — its neighbours are a bracket, a comma,
  or the ends of the string — which is exactly those two cases and nothing else. `(a+b)*c` keeps the
  brackets that make it what it is, and brackets inside a string literal are read as text. Verified
  against MariaDB 11.8, MySQL 8.0 and MySQL 8.4: declared and live now normalize identically for
  every unchanged column.

  A genuine difference is reported as `Assisted` with the `MODIFY COLUMN` that adopts it, and the
  reason quotes **both** spellings — so an operator can judge whether the difference is real, and a
  normalization gap is recognisable at a glance rather than chased.

## [0.5.1] - 2026-08-08

Two misclassifications of a precision/scale change, in opposite directions. Both came from judging
`precision` and `scale` as independent facets.

### Fixed

- **`datetime` → `datetime(6)` was classified `Destructive`.** Gaining fractional-second digits
  preserves every stored value, so it is a widening. A null dimension was being read as "unknown,
  assume the worst", but null means **zero** here: both normalizers deliberately collapse an
  explicit `0` to null so that `datetime` and `datetime(0)` compare equal. The comparison was
  therefore `6 >= 0` all along.

  Only the classification was wrong — the ALTER itself was correct, and would have been applied by
  any run at the `Destructive` ceiling.

- **`decimal(12,0)` → `decimal(12,2)` was classified `Safe`, and it is not.** Scale is carved *out
  of* precision, so growing it within a fixed precision moves digits across the point: the integer
  range falls from twelve digits to ten, and every value ≥ 10^10 is rejected by the ALTER. Judged
  facet-by-facet that reads as "scale grew, precision unchanged" — a widening — so it passed the
  gate and would have been applied unattended at the default ceiling.

  This was the more serious of the two: the first is over-caution, this one let a data-rejecting
  change through the ceiling that exists to stop it.

  The two facets are now judged together, which is the only way to state the rule: the fractional
  digits must not shrink, **and** neither must the integer digits they leave behind. So
  `decimal(10,2)` → `decimal(12,4)` stays `Safe` (eight integer digits either side, fraction grows)
  while `decimal(12,0)` → `decimal(12,2)` is `Destructive`.

## [0.5.0] - 2026-08-08

A constraint rename used to converge *halfway*. That is now one atomic change, classified by what it
actually costs on each engine.

### Added

- **Foreign-key renames are detected by shape and applied atomically.** A rename reaches the differ
  as an unmatched desired name plus an unmatched live name. Emitted separately those classify
  differently — the add `Safe`, the drop `Destructive` — so at the default `Safe` ceiling the add
  went through and the drop did not: the column ended up carrying **both** constraints, plus the
  redundant index behind the second one, and the plan never re-planned empty.

  Leftovers are now paired by **shape** — local columns, target table and columns, referential
  actions — and emitted as a single `rename_foreign_key` change, so one ceiling decision covers both
  statements. Measured against MariaDB, before and after:

  ```
  before   add_foreign_key   …  safe          -> applied
           drop_foreign_key  …  destructive   -> skipped     = two constraints, plan never empty
  after    rename_foreign_key … destructive   -> not applied = untouched; Destructive run converges,
                                                               and re-plans empty
  ```

  Inferring a *constraint* rename does not contradict the standing rule that **column** renames must
  be declared. A constraint holds no data and its shape fully determines what it enforces, so two
  identical shapes under different names are provably the same constraint. A column rename is
  refused precisely because there the data is the point and a wrong pairing destroys it.

  **Classified per dialect, by real cost.** PostgreSQL has `ALTER TABLE … RENAME CONSTRAINT`, a
  catalogue-only update: `Safe`, so an existing install self-heals on its next convergence. MySQL
  and MariaDB have no equivalent, so the same outcome costs an `ADD FOREIGN KEY` that validates
  every existing row under a metadata lock, plus a `DROP` — real work on a large table and not
  something to do unattended at boot: `Destructive`. SQLite cannot address a constraint separately:
  `Manual`. The MySQL fallback adds **before** dropping, so the column is never momentarily
  unconstrained.

  Pairing is **declined when ambiguous**: two live constraints of identical shape are already
  redundant with each other, so there is no fact about which was renamed, and guessing would drop an
  arbitrary one. That case falls back to plain add + drop, leaving the decision with the operator.

### Changed

- **Requires `nandan108/attrecord` `^0.15 || ^0.16`** (was `^0.15`).

  attrecord 0.16.0 changes how FK constraint names are derived, which is exactly the drift this
  release handles — so the union matters: it lets a consumer install this release **first**, while
  still on 0.15, and upgrade attrecord afterwards with the rename handling already in place. A plain
  `^0.16` would have forced both at once, and the very first convergence after that jump is the one
  needing the protection.

  Nothing here depends on 0.16: the feature works purely off live-versus-declared names. The suite
  is verified green against both.

- **`AlterEmitter` gains `renameForeignKey()`**, returning `null` on engines with no such operation
  (MySQL, MariaDB, SQLite) so the differ falls back to add + drop. An external implementer of that
  interface would need the new method — none are known.

### Fixed

- **Two unit fixtures no longer hard-code attrecord's FK constraint names**, reading them off the
  schema instead. They encoded 0.15's spelling, which would have silently mis-tested against 0.16 —
  in one case turning an "in sync, expect empty plan" assertion into an undetected rename.

## [0.4.1] - 2026-08-06

A dependency floor and nothing else: no public API change, no behaviour change.

### Changed

- **Requires `nandan108/attrecord` ^0.15** (was ^0.14).

  Nothing here needs 0.15.0's feature (a backed enum case as a column default) — the diff pipeline
  never sees one, because attrecord unwraps the case in `TableSchema::fromClass` and leaves
  `ColumnDefinition::$default` typed `int|float|string|bool|null`. `TableSchema` is exactly the
  boundary this package reads from, so `AbstractColumnNormalizer`'s `(string) $col->default` is
  never handed a `\BackedEnum`.

  The floor moves because the constraints have to stay satisfiable, not because the code does.
  `^0.14` and `^0.15` are disjoint under caret semantics on 0.x, so a project depending on both this
  package and a consumer already requiring attrecord ^0.15 could not resolve at all.

  Verified against a real database rather than assumed: a Record declaring three enum-case defaults
  — string-backed on an `Enum` column, string-backed on a `VarChar`, int-backed on a
  `TinyIntUnsigned` — converges from empty and **re-plans empty**, with `DEFAULT 'active'` / `3` /
  `'draft'` emitted as written. The golden invariant holds across the version change.

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

[Unreleased]: https://github.com/Nandan108/attrecord-migrations/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.2...v0.6.0
[0.5.2]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Nandan108/attrecord-migrations/releases/tag/v0.1.0
