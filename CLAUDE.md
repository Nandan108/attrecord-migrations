# attrecord-migrations — Project Standards

Declarative schema convergence for attrecord. **The design contract is
[attrecord's docs/arch-migrations.md](../attrecord/docs/arch-migrations.md)** — read it before
changing behavior; §7 (Non-goals) is the fence. This package follows **attrecord's conventions
wholesale** ([../attrecord/CLAUDE.md](../attrecord/CLAUDE.md)): same tri-backend Definition of
Done, same cross-dialect gotcha discipline, same release rules.

## Definition of Done (inherited from attrecord — differences only)

1. **Tests pass on ALL THREE backends** (MySQL/MariaDB, PostgreSQL, SQLite). A skipped backend is
   NOT a passing backend. Databases: reuse attrecord's containers
   (`docker compose up -d` in `../attrecord`; MySQL :3306 root/root, PG :5432 postgres/postgres);
   this package's integration tests use their own database (`attrecord_migrations_test`),
   auto-created by the test support layer — never attrecord's `attrecord_test`.
2. `composer psalm` zero errors (level 1). `composer cs-fix` before commit.
3. Docs move with code: README.md + CHANGELOG.md here; behavioral design changes go to the
   **design contract in attrecord's repo** (arch-migrations.md), not here.
4. This package **never writes through core seams that don't exist** — if a need surfaces, the
   seam is added to attrecord first (with its own tests), then consumed here.

## Safety invariants (the reason this package is separate)

- `plan()` is pure — introspection reads only; it must never execute DDL.
- `apply()` honors the ChangeClass ceiling: Safe by default; Destructive only by explicit opt-in;
  **Manual is never auto-applied**.
- Fail-safe bias: a normalizer/differ that is unsure classifies as Manual — never guesses an ALTER.
- Never drop unmanaged tables or unmanaged live-side objects (they're invisible, not deletable).
- Advisory-lock every apply; every statement individually idempotent-by-replan (MySQL has no
  transactional DDL — there is no atomic converge anywhere, by design).

## Local dev

attrecord is consumed via a composer **path repository** (`../attrecord`, symlinked) — changes to
core are visible immediately; remember they must be committed/released in attrecord itself.

CI reproduces that layout by checking out `Nandan108/attrecord` (ref `main`, overridable via the
workflow's `ATTRECORD_REF`) as a sibling directory, so the path repository resolves and every build
tests against core's development head rather than a possibly stale release.

## Commit & release

- Detailed, conventional commits. Breaking changes get a `!` and a **Breaking** note in the CHANGELOG.
- **Releases are CI-gated — do NOT hand-create `vX.Y.Z` tags.** Packagist publishes on tag existence,
  so the tag must be a *product* of green CI, not a precondition. Flow: promote the CHANGELOG section →
  push the release commit to `main` → run the **Release** workflow (Actions → Release → Run workflow →
  `version: X.Y.Z`). It re-runs the full matrix and only then creates the annotated tag + GitHub
  release, so a red build never reaches Packagist.
- **The release gate refuses a path-repository release.** `../attrecord` is a development
  convenience that cannot resolve for anyone installing from Packagist, so the workflow fails if
  `composer.json` still declares it or still requires a `dev-` constraint of `nandan108/attrecord`.
  Publishing therefore has a hard prerequisite: **attrecord must be released first** with the
  schema-evolution seams, and the constraint pointed at it.
- **`v*` tag ruleset** + **`RELEASE_TOKEN`**: replicate attrecord's setup — a ruleset restricting
  `v*` tag creation to the admin bypass, and a fine-grained PAT (**Contents: read & write** +
  **Metadata: read**, on this repo) stored as the `RELEASE_TOKEN` secret. `GITHUB_TOKEN` is not a
  bypass actor, so without that secret the workflow fails at the tag push — deliberately, with an
  explanatory error.
