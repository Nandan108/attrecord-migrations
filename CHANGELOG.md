# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.0] - 2026-09-05

**One bug, released as a minor.** The whole of this release is a bug fix, but fixing it required
widening a published seam: `AlterEmitter` gains a parameter on `renameColumn()` and one new method.
An optional parameter is source-compatible for *callers* and breaking for *implementers* — a class
implementing the old signature no longer matches and PHP fatals on load — and every previous change
of that kind here was a minor (`addCheck()` / `dropCheck()` in 0.6.0, `renameIndex()` in 0.8.0,
`renameForeignKey()` in 0.5.0). The seam sets the version, not the intent.

The three in-package emitters are the only implementations known to exist, so for a consumer that
does not implement `AlterEmitter` this is a drop-in upgrade.

Requires attrecord `^0.21`.

### Fixed

- **A declared column rename no longer emits DDL MySQL refuses.** `#[Column(renamedFrom: 'old')]`
  planned a single `CHANGE COLUMN`, and MySQL rejects that outright — `ERROR 3108, Column 'old' has
  a generated column dependency` — whenever another column's `GENERATED ALWAYS` expression names the
  one being renamed. The `ALTER` does not run at all.

  **MariaDB accepts the same statement and rewrites the stored expression to the new name by
  itself**, which is what made this invisible: the planner was correct for MariaDB, correct to plan
  no change for the dependent column, and wrong on both counts for MySQL. Every local engine here is
  MariaDB, so it passed every check that was not CI.

  The differ now finds the generated columns whose **live** expression names the column being
  renamed — the live one, because the desired expression already names the new column and so says
  nothing about what the rename will collide with — and hands them to the emitter. MySQL emits **one
  `ALTER`** that re-points each dependent at the new name and renames the column, in that order.
  PostgreSQL and SQLite rewrite references themselves and ignore the parameter; MariaDB does not need
  it either, but one emitter serves both MySQL-family engines and cannot tell them apart at
  SQL-build time, and MariaDB accepts the compound form with the same result.

  **The clause order is load-bearing.** `MODIFY` before `CHANGE` succeeds on both engines; `CHANGE`
  before `MODIFY` fails with `ERROR 1054, Unknown column 'old'`, the engine having validated the
  still-old expression against the already-renamed column.

  The trigger is checkable from the declarations alone, without a database: a
  `#[Column(renamedFrom: 'x')]` where `x` appears in some other column's `generatedAs`.

  Verified against a real `mysql:8.0.46` container and MariaDB 10.11: the bare `CHANGE COLUMN`
  reproduces 3108, and the emitted statement renames with data intact, the generated column
  recomputing from the new name, and every index still in place.

- **Re-specifying the dependent rather than rebuilding it keeps its indexes.** Dropping a generated
  column takes its indexes with it — an index over it alone disappears outright, a composite index
  silently loses that column and keeps the rest — and adding the column back restores none of them.
  There is no error and no wrong answer, only queries that quietly stop using an index, which is the
  same failure shape as 3108 being silent on MariaDB. `MODIFY COLUMN` never removes the column, so
  the question does not arise.

  The regression test uses an **indexed** dependent for that reason: an unindexed one converges
  either way and so cannot tell the two implementations apart. Caught in review by a consumer lane
  that went looking for a table its own could not test against
  (`invflux_subject_identifiers`, where the generated column exists for its index and nothing else
  reads it) — thank you.

- **An enum whose members are not lower case now converges.** MySQL introspection lower-cased the
  whole of `COLUMN_TYPE`, which is right for the type keyword and wrong for `enum(…)` / `set(…)`,
  where the parenthesised part is not syntax but the member values themselves. A column declared
  `enumValues: ['EXW', 'FCA', 'DDP']` therefore read back as `['exw', 'fca', 'ddp']` and was
  **permanently drifted on `members`**: the differ planned a `MODIFY COLUMN` byte-identical to what
  was already live, applying it changed nothing, and the next plan said exactly the same. Nothing
  errored and nothing converged — a standing `Destructive` entry that teaches a reader to stop
  reading drift reports.

  Folding now skips quoted literals. Folding only up to the first `(` would not have worked: on the
  integer families `unsigned` and `zerofill` follow the closing paren and the patterns that read
  them are case-sensitive.

  MySQL-family only — PostgreSQL and SQLite carry an enum's members in a CHECK constraint, on a
  separate field that was never folded.

  The kitchen-sink fixture gained a **deliberately upper-case** enum, since a lower-case one
  converges whether or not the case survives the round trip. With the fold restored, 21 tests fail;
  before it was added, none did. Reported by a consumer lane that hit it on a real column — a
  purchase order's Incoterm, upper case because the ICC's own spelling is.

### Changed

- **`AlterEmitter::renameColumn()` takes `array $dependents = []`** — the generated columns, as
  *desired* `ColumnDefinition`s, whose live expression names the column being renamed. An
  implementation that ignores it is correct on PostgreSQL and SQLite, which rewrite the references
  themselves; the two in-package emitters for those engines document why they discard it rather than
  leaving the reader to infer it.

- **`AlterEmitter::renameRespecifiesDependents(): bool`** is new, and is what lets a rename be priced
  per engine instead of globally. A rename whose dependent is **`STORED`** now classifies as
  `Assisted` on the MySQL family — the statement is known and correct, but re-pointing a stored
  expression recomputes the column for every row, which is exactly the "known SQL, too consequential
  to run unattended" case `Assisted` was introduced for. On PostgreSQL and SQLite the very same
  rename stays `Safe`: nothing is re-specified there, so there is nothing to hold back. A `VIRTUAL`
  dependent stores nothing and never escalates.

- **Requires attrecord `^0.21`** (was `^0.19 || ^0.20`). Not for this fix — 0.21.0 carries
  `deleteUnreferenced()`, which nothing here calls. The floor moves because `^0.20` and `^0.21` are
  disjoint under the caret, so a graph where anything has moved to 0.21 cannot also hold a package
  pinned below it. Consumers on 0.19/0.20 stay on 0.8.1 until they are ready to move.

### Documentation

- **`MysqlIntrospector::tryFetch()` names the failure it can hide.** Discarding the cause is right
  there — a failed catalogue probe genuinely is the answer "this engine has no such view", since
  `information_schema.CHECK_CONSTRAINTS` carries `TABLE_NAME` on MariaDB and not on MySQL. What was
  missing is that a *real* failure is indistinguishable from that answer, and lands two steps from
  its cause: the introspector reports no live CHECK constraints, the differ plans to add every
  declared one, and the golden invariant fails — a freshly created table stops re-planning empty.

  The note also says the probe is a **fallback chain**, not a single query: `checks()` tries the
  MariaDB shape and falls back to the MySQL shape, so an empty result means both failed and two
  causes were collapsed into one null. Running only one of them by hand proves nothing.

## [0.8.1] - 2026-09-02

### Changed

- **Admits attrecord `^0.20` alongside `^0.19`** (`^0.19 || ^0.20`). 0.20.0 adds the `Immutable`
  marker and makes `AppendOnly` extend it — Record-layer semantics this package neither reads nor
  needs, so it works unchanged against either.

  A **union** rather than a bump, because that is what is true: nothing here requires 0.20, so a
  consumer still on 0.19 has no reason to be dragged over. It matters that the two carets are
  **disjoint** — `^0.19` and `^0.20` cannot both be satisfied — so a package pinned to the older one
  inside a graph where anything else has moved makes the whole graph unresolvable. Local development
  hides this (path repositories resolve regardless); CI, which fresh-resolves rather than reading the
  lock, is where it surfaces.

## [0.8.0] - 2026-08-23

**Index renames, and three answers the differ could not work out for itself.** Requires attrecord
`^0.19`, which carries the declarations.

**Contains a behaviour change**: an index rename now plans as one `rename_index` change, and a
reshaped index as one `replace_index`, where both previously planned as a separate drop and create.

### Fixed

- **An index rename no longer half-applies.** Renaming an index emitted a `create_index` (Safe) and
  a `drop_index` (Destructive), so a run at the default ceiling built the new index and left the old
  one in place — permanently, since on the next pass the leftover simply reads as an ordinary
  undeclared index. Nothing errored; the table just carried two identical indexes and paid for both
  on every write.

  Unmatched adds and drops are now paired the way foreign keys already were: identical shape on both
  sides means the same index under a new name, emitted as **one** `rename_index`. Ambiguity (two
  identically-shaped orphans) declines the pairing and falls back to plain create + drop.

  Almost every engine renames in the catalogue — `RENAME INDEX` on MySQL 5.7+/MariaDB 10.5.2+,
  `ALTER INDEX … RENAME TO` on PostgreSQL. SQLite creates the new index before dropping the old, the
  opposite order to the foreign-key fallback and for the mirror-image reason: an index is never left
  momentarily missing.

### Added

- **`renamedFrom:` on `#[Index]` / `#[UniqueKey]`** (attrecord 0.19.0) beats the shape heuristic, and
  is the only thing that survives an index being renamed **and** reshaped in one release — at that
  point nothing about the two shapes relates them. A catalogue rename keeps the live columns, so a
  declared rename whose shape also changed is emitted as a rebuild under the new name rather than a
  rename.

- **`#[Absent(...)]` reclassifies a drop by answering the ownership question.** An index or unique
  key declared absent drops at the **Safe** ceiling; undeclared it stays Destructive, because an
  index forbids nothing and so contradicts nothing — it is as likely to be an operator's tuning
  index, and dropping that degrades a query plan silently. A foreign key or CHECK was already Safe
  (0.7.0), so there it only sharpens the reason. **A column stays Destructive**: saying you meant it
  does not bring the values back. What the declaration buys there is provenance — the plan now
  distinguishes `column declared absent since 1.4.0` from `column exists live but is not declared`,
  so an operator reading a Destructive plan can tell the deliberate removal from the surprise.

- **`#[Unmanaged(...)]` excludes one named object from convergence** — never dropped, never
  converged, never reported. `PartiallyDeclared` answers the same question for a whole table and
  buys its silence by going quiet about all drift there; naming one object leaves everything else
  under the differ's eye. Prefer it wherever the objects can be named.

### Changed

- **A reshaped index is one `replace_index` change** carrying both statements, not a drop plus a
  create. Emitted separately they could be authorised separately, and a ceiling admitting the drop
  alone would leave the table with neither the live shape nor the desired one — the same reasoning
  that merged `replace_foreign_key` in 0.7.0.

- `PlannedChange::KINDS` gains `rename_index` and `replace_index`.

## [0.7.0] - 2026-08-21

Two findings from a consumer's real migration — a goods receipt's parent generalising from a hard
foreign key to a polymorphic, deliberately FK-less reference.

**Contains a behaviour change**: foreign-key drops now run at the default `Safe` ceiling, so an
`apply()` that previously left them pending will apply them. That is the point of the change, and it
is why this is a minor rather than a patch.

### Changed

- **A constraint drop is `Safe`, not `Destructive`** — foreign keys and CHECKs alike. By the
  classification's own criterion they were on the wrong shelf: dropping a constraint removes no row
  and no column value, and re-adding it immediately always succeeds, because the data that satisfied
  it still does. "Drops" in the `Destructive` description means drops of *data-bearing* things.

  The sharper reason is what an undeclared foreign key **is**: a rule forbidding writes the Records
  permit, so leaving it is drift that silently overrules the declared schema — and it does damage.
  MySQL's `CHANGE COLUMN` carries a column's constraints along with it, so a *declared rename* can
  leave an `ON DELETE CASCADE` aimed at a table the column no longer belongs to. Every existing row
  looks fine; the next parent delete quietly removes children that stopped being its children. The
  differ noticed that constraint and then declined to drop it, because dropping was `Destructive`.

  Four sites move, not one: the undeclared-FK drop, the shape-differs recreate, the add-then-drop
  fallback used for a rename on engines with no `RENAME CONSTRAINT`, and the undeclared-CHECK drop. The last of those
  was `Destructive` while the identical plain `add_foreign_key` was already `Safe` with
  `mayRejectExistingRows` — classifying a pair harder than its own riskiest half.

  The line drawn is **contradiction, not losslessness**, which is why an undeclared *index* stays
  `Destructive`: it forbids nothing, so it adds to the model instead of overruling it. Foreign keys
  and CHECKs both forbid. (The report that prompted this only raised foreign keys; extending it to
  CHECKs is what keeps the criterion coherent rather than leaving the same argument half-applied.)
  Ownership of a table is a different axis again, and `PartiallyDeclared` already answers it — the
  right home for "do not touch what I did not declare", rather than the loss-based ladder.

- **A changed foreign key is one `replace_foreign_key`**, carrying the drop and the add together,
  where it used to be a separate `drop_foreign_key` and `add_foreign_key`. Emitted apart they could
  be authorised apart, and a ceiling admitting only the drop would leave the column with no
  constraint at all — the one outcome neither the old classification nor the new one wants.

### Added

- **`Plan::withStep(before:|after:, run:)` — change-attached data steps**, the half of the design
  contract's §6 that was specified and never built. For a transform whose marker *is* the schema
  delta: wrap a column's values before the `MODIFY` that would reject them, backfill a column right
  after the `ADD` that created it. Run-once `dataStep()` remains the other half, for content changes
  with no delta to attach to.

  Steps run at their position in the apply order — placement being the whole point — inside the same
  advisory lock, and are recorded in the run ledger. A failing step stops the run and is reported
  against the change it was attached to, because that is what tells a reader where the database
  now stands.

  The selector is `"kind table.subject"` in the differ's own vocabulary, the same strings a plan
  prints. `withStep()` returns a **new** plan, so `Plan` stays the pure value object it is
  advertised as.

- **`PlannedChange::KINDS`** — the kind vocabulary, published so a selector naming a kind that does
  not exist is refused where it is written.

### Two honesty corrections to the specification

- **§6.1's "the pair applies as a unit" was not deliverable and has been withdrawn.** DDL
  auto-commits on MySQL and MariaDB, so a step and its change cannot share a transaction there, and
  no API in this package can change that. PostgreSQL and SQLite have transactional DDL and let a
  caller wrap the whole apply. Documented as a per-engine property rather than promised flatly.

- **Consequently the `NOT NULL`-without-default case stays `Manual`**, where §6.1 claimed attaching
  a backfill would unlock it — the pair would have to be atomic for the column to be tightened
  safely in one pass. The differ's guidance now names the path that actually works: add the column
  nullable, backfill it with an `after:` step, tighten it in a later release once the data satisfies
  the constraint.

## [0.6.1] - 2026-08-21

### Changed

- **Admit attrecord `^0.18`** alongside `^0.17.1`. 0.18.0 adds `ReferenceReader` — reading which
  foreign keys point *at* a table — and changes nothing this package uses; the suite is green on it
  across all three backends, which is what makes this a floor widening rather than a version bump.

  Stated as a union rather than a move, so a consumer still on 0.17 is not forced across by
  installing this.

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

[Unreleased]: https://github.com/Nandan108/attrecord-migrations/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.2...v0.6.0
[0.5.2]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/Nandan108/attrecord-migrations/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Nandan108/attrecord-migrations/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Nandan108/attrecord-migrations/releases/tag/v0.1.0
