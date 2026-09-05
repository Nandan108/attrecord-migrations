<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Diff;

use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\SchemaObjectKind;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\AttrecordMigrations\Emit\AlterEmitter;
use Nandan108\AttrecordMigrations\Live\LiveForeignKey;
use Nandan108\AttrecordMigrations\Live\LiveIndex;
use Nandan108\AttrecordMigrations\Live\LiveTable;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;

/**
 * The diff + classifier (the design contract §4.3): compares one Record's desired `TableSchema`
 * against its introspected {@see LiveTable} and emits classified {@see PlannedChange}s.
 *
 * Fail-safe bias throughout: anything the pipeline is unsure about — an unparseable live type, an
 * ambiguous facet (generated exprs, auto-increment drift), a *changed* PK, an engine
 * without the needed ALTER — becomes a **Manual** change with a reason and no SQL. The differ
 * never guesses an ALTER.
 *
 * A **column** rename is never inferred: only a `#[Column(renamedFrom: 'old')]` declaration produces
 * a data-preserving rename (prior art is unanimous that drop+add similarity inference is a trap).
 * Indexes and constraints are different in kind — they carry no data, so a wrong guess costs a
 * rebuild rather than rows — and there an identically-shaped orphan on each side is read as a
 * rename, with `renamedFrom:` available for the case where the shape changed too.
 *
 * @see the design contract — the design contract this implements.
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class SchemaDiffer
{
    public function __construct(
        private readonly ColumnNormalizer $normalizer,
        private readonly AlterEmitter $emitter,
        private readonly SqlDialect $dialect,
    ) {
    }

    /**
     * @param list<string> $omitForeignKeys   constraint names deferred out of `CREATE TABLE` to break a
     *                                        dependency cycle; each comes back as its own `add_foreign_key`
     *                                        change, which the caller orders after every create
     * @param bool         $partiallyDeclared when true, nothing live-but-undeclared is proposed for
     *                                        dropping — the table's shape is only partly described by
     *                                        this schema. See {@see \Nandan108\AttrecordMigrations\PartiallyDeclared}
     *
     * @return list<PlannedChange>
     */
    public function diffTable(TableSchema $desired, ?LiveTable $live, array $omitForeignKeys = [], bool $partiallyDeclared = false): array
    {
        $table = $desired->tableName;

        if (null === $live) {
            $statements = array_values(array_filter(
                explode(";\n", $this->dialect->buildCreateTable($desired, omitForeignKeys: $omitForeignKeys)),
                static fn (string $s): bool => '' !== trim($s),
            ));

            $changes = [new PlannedChange($table, 'create_table', '', ChangeClass::Safe, $statements, 'table does not exist')];

            // Deferred constraints are the reason this table could be created at all: their targets
            // are only guaranteed to exist once every create has run, so they are added separately.
            foreach ($desired->foreignKeys as $fk) {
                if (\in_array($fk->constraintName, $omitForeignKeys, true)) {
                    $changes[] = $this->fkAdd($table, $fk->constraintName, $fk);
                }
            }

            return $changes;
        }

        $changes = [];

        // --- Primary key: never altered automatically. ---
        // pkColumns() is the whole key — a single-entry list on an ordinary table, every member on
        // a composite one. Reading `pk` here instead compared only the first member, so a table
        // declaring #[PrimaryKey(columns: …)] could never match its own live definition and
        // reported Manual drift forever, including immediately after being created.
        $desiredPk = $desired->pkColumns();
        if ($live->primaryKey !== $desiredPk) {
            $changes[] = $this->manual($table, $desired->pk, sprintf(
                'primary key differs (live: [%s], desired: [%s]) — PK changes are never auto-applied',
                implode(', ', $live->primaryKey),
                implode(', ', $desiredPk),
            ));
        }

        // --- Columns. ---
        // A generated column rebuilt as part of a declared rename must not also be diffed on its
        // own: the rebuild re-adds it from the desired definition, so any drift in it is already
        // resolved. Computed up front because the dependent may be declared either side of the
        // column being renamed.
        $rebuiltByRename = self::columnsRebuiltByRename($desired, $live);

        $liveColumnsClaimed = [];
        foreach ($desired->columns as $colName => $col) {
            if (isset($live->columns[$colName])) {
                $liveColumnsClaimed[$colName] = true;
                if (isset($rebuiltByRename[$colName])) {
                    continue; // re-added from this same definition by the rename below
                }
                foreach ($this->diffColumn($table, $col, $live) as $change) {
                    $changes[] = $change;
                }
                continue;
            }

            // Declared rename: the old column must exist live and must not also be desired.
            $from = $col->renamedFrom;
            if (null !== $from && isset($live->columns[$from]) && !isset($desired->columns[$from])) {
                $liveColumnsClaimed[$from] = true;

                // MySQL refuses to rename a column that a generated column's expression names
                // (error 3108) — MariaDB accepts it and rewrites the expression itself, so this is
                // invisible on a MariaDB dev machine and fatal on a MySQL install. The dependents
                // travel to the emitter, which rebuilds them around the rename where it must.
                $dependents = self::generatedDependents($desired, $live, $from);
                $stored = array_values(array_filter(
                    $dependents,
                    static fn (ColumnDefinition $d): bool => GeneratedColumnMode::Stored === ($d->generatedMode ?? GeneratedColumnMode::Stored),
                ));
                if ([] !== $stored) {
                    // Rebuilding a STORED dependent rewrites every row, and on MariaDB it would buy
                    // nothing at all — so this is handed to a person rather than done quietly.
                    $changes[] = $this->manual($table, $colName, sprintf(
                        'declared rename from "%s", but the STORED generated column %s depends on it. '
                        .'MySQL refuses the rename outright (error 3108) and rebuilding a stored column '
                        .'rewrites every row, so do it deliberately: drop %s, rename the column, then '
                        .'re-add it. MariaDB would accept the plain rename and fix the expression itself.',
                        $from,
                        implode(', ', array_map(static fn (ColumnDefinition $d): string => '"'.$d->name.'"', $stored)),
                        implode(' and ', array_map(static fn (ColumnDefinition $d): string => '"'.$d->name.'"', $stored)),
                    ));
                    continue;
                }

                $changes[] = new PlannedChange(
                    $table,
                    'rename_column',
                    $colName,
                    ChangeClass::Safe,
                    $this->emitter->renameColumn($table, $from, $col, $dependents),
                    [] === $dependents
                        ? "declared rename from '{$from}' (data-preserving)"
                        : sprintf(
                            "declared rename from '%s' (data-preserving); the virtual generated column%s %s "
                            .'is rebuilt around it, which MySQL requires and MariaDB does not',
                            $from,
                            1 === \count($dependents) ? '' : 's',
                            implode(', ', array_map(static fn (ColumnDefinition $d): string => '"'.$d->name.'"', $dependents)),
                        ),
                );
                // MySQL's CHANGE COLUMN re-specifies the whole column in the same statement; on
                // engines where rename is rename-only (PG/SQLite) any remaining drift surfaces on
                // the next converge — one deliberate step at a time, never a guessed compound.
                continue;
            }

            $restriction = $this->emitter->addColumnRestriction($col);
            if (null !== $restriction) {
                $changes[] = $this->manual($table, $colName, $restriction);
                continue;
            }
            if (!$col->nullable && null === $col->default && null === $col->defaultExpr && !$col->isGenerated) {
                $changes[] = $this->manual($table, $colName, 'adding a NOT NULL column without a default: existing rows would have no value — give it a '
                        .'default, make it nullable, or add it nullable now and tighten it in a later release once '
                        .'a step (Plan::withStep) has backfilled it');
                continue;
            }
            $changes[] = new PlannedChange(
                $table,
                'add_column',
                $colName,
                ChangeClass::Safe,
                $this->emitter->addColumn($table, $col),
                'column missing',
            );
        }

        foreach (array_keys($live->columns) as $colName) {
            $colName = (string) $colName; // numeric-string array key -> int; see above
            if ($partiallyDeclared) {
                break; // the undeclared columns belong to someone else — see PartiallyDeclared
            }
            if (isset($desired->unmanaged[SchemaObjectKind::Column->value][$colName])) {
                continue; // another authority maintains it — see Attribute\Unmanaged
            }
            if (!isset($desired->columns[$colName]) && !isset($liveColumnsClaimed[$colName])) {
                $statements = $this->emitter->dropColumn($table, $colName);
                $absent = $desired->absent[SchemaObjectKind::Column->value][$colName] ?? null;
                $changes[] = new PlannedChange(
                    $table,
                    'drop_column',
                    $colName,
                    // Destructive either way: saying you meant it does not bring the values back,
                    // and this is the one kind of drop where the ceiling is protecting data rather
                    // than adjudicating ownership. What the declaration changes is what an operator
                    // reading the plan can tell — the deliberate removal from the surprise.
                    ChangeClass::Destructive,
                    $statements,
                    null === $absent
                        ? 'column exists live but is not declared — dropping destroys its data'
                        : 'column '.$absent->describe().' — dropping destroys its data',
                );
            }
        }

        // --- Unique keys + indexes (compared by name, then by shape). ---
        // array-key, not string: a numerically-named index (`#[Index('1', …)]`, or an engine's own
        // ordinal naming on the live side) becomes an int the moment it is used as an array key.
        /** @var array<array-key, array{columns: list<string>, unique: bool}> $desiredIndexes */
        $desiredIndexes = [];
        foreach ($desired->uniqueKeys as $name => $cols) {
            $desiredIndexes[$name] = ['columns' => $cols, 'unique' => true];
        }
        foreach ($desired->indexes as $name => $cols) {
            $desiredIndexes[$name] = ['columns' => $cols, 'unique' => false];
        }

        // Unmatched-by-name entries are held back and paired below, exactly as the foreign keys
        // are: an index *rename* looks like an unrelated index appearing and another disappearing,
        // and those classify differently (create is Safe, drop is Destructive), so emitting them
        // independently lets a Safe-ceiling run apply half a rename — building the new index while
        // the old one stays behind forever, silently doubling the write cost of the table.
        /** @var array<array-key, array{columns: list<string>, unique: bool}> $pendingIndexAdds */
        $pendingIndexAdds = [];
        /** @var array<array-key, LiveIndex> $pendingIndexDrops */
        $pendingIndexDrops = [];

        foreach ($desiredIndexes as $name => $spec) {
            // A declared name that looks numeric arrives as an int (PHP array-key coercion), and
            // every emitter below is typed for a string.
            $name = (string) $name;
            $liveIx = $live->indexes[$name] ?? null;
            if (null === $liveIx) {
                $pendingIndexAdds[$name] = $spec;
                continue;
            }
            if ($liveIx->columns !== $spec['columns'] || $liveIx->unique !== $spec['unique']) {
                // One change carrying both statements — same reasoning as `replace_foreign_key`
                // below: a ceiling that admitted the drop alone would leave the table with no
                // index of that name at all, which is neither the live shape nor the desired one.
                $changes[] = new PlannedChange(
                    $table,
                    'replace_index',
                    $name,
                    ChangeClass::Destructive,
                    [
                        ...$this->emitter->dropIndex($table, $name),
                        ...$this->emitter->createIndex($table, $name, $spec['columns'], $spec['unique']),
                    ],
                    sprintf(
                        'index shape differs (live: [%s]%s, desired: [%s]%s) — dropped and recreated',
                        implode(', ', $liveIx->columns),
                        $liveIx->unique ? ' unique' : '',
                        implode(', ', $spec['columns']),
                        $spec['unique'] ? ' unique' : '',
                    ),
                    mayRejectExistingRows: $spec['unique'],
                );
            }
        }

        if (!$partiallyDeclared) {
            foreach ($live->indexes as $name => $liveIx) {
                $name = (string) $name; // numeric-string array key -> int; see above
                if (isset($desiredIndexes[$name])) {
                    continue;
                }
                // MySQL implicitly creates a supporting index for every FK constraint (named after
                // it) when none exists — that index is FK plumbing, not drift.
                if (self::isForeignKeyPlumbing($live, $name)) {
                    continue;
                }
                if (isset($desired->unmanaged[SchemaObjectKind::Index->value][$name])
                    || isset($desired->unmanaged[SchemaObjectKind::UniqueKey->value][$name])) {
                    continue; // the DBA's tuning index, declared as theirs — see Attribute\Unmanaged
                }
                $pendingIndexDrops[$name] = $liveIx;
            }
        }

        foreach ($pendingIndexAdds as $newName => $spec) {
            $newName = (string) $newName; // numeric-string array key -> int; see above
            $oldName = self::indexRenameSource($desired, $newName, $spec, $pendingIndexDrops);
            if (null === $oldName) {
                $changes[] = new PlannedChange(
                    $table,
                    'create_index',
                    $newName,
                    ChangeClass::Safe,
                    $this->emitter->createIndex($table, $newName, $spec['columns'], $spec['unique']),
                    ($spec['unique'] ? 'unique key' : 'index').' missing',
                    mayRejectExistingRows: $spec['unique'],
                );
                continue;
            }
            $wasDeclared = isset($desired->indexRenames[$newName]);
            $fromLive = $pendingIndexDrops[$oldName];
            unset($pendingIndexDrops[$oldName]);
            $changes[] = $this->indexRename($table, $fromLive, $newName, $spec, $wasDeclared);
        }

        foreach ($pendingIndexDrops as $name => $liveIx) {
            $name = (string) $name; // numeric-string array key -> int; see above
            // Declared absent by either name for the kind: which of the two an engine calls a
            // retired object is a detail the author has no reason to remember years later.
            $absent = $desired->absent[SchemaObjectKind::Index->value][$name]
                ?? $desired->absent[SchemaObjectKind::UniqueKey->value][$name]
                ?? null;
            $changes[] = new PlannedChange(
                $table,
                'drop_index',
                $name,
                // An index forbids nothing, so an unrecognised one contradicts nothing either and
                // is as likely to be an operator's tuning index as our own leftover — dropping it
                // costs a query plan and buys only tidiness. A declaration settles that question,
                // and only a declaration can: see Attribute\Absent.
                null === $absent ? ChangeClass::Destructive : ChangeClass::Safe,
                $this->emitter->dropIndex($table, $name),
                null === $absent
                    ? ($liveIx->unique ? 'unique key' : 'index').' exists live but is not declared (it may be an operator-added tuning index — dropping requires opt-in)'
                    : ($liveIx->unique ? 'unique key' : 'index').' '.$absent->describe(),
            );
        }

        // --- Foreign keys (by constraint name). ---
        /** @var array<array-key, ForeignKeyDefinition> $desiredFks — array-key: see above */
        $desiredFks = [];
        foreach ($desired->foreignKeys as $fk) {
            $desiredFks[$fk->constraintName] = $fk;
        }
        // Unmatched-by-name entries are held back rather than emitted here: a constraint *rename*
        // looks like an unrelated add plus an unrelated drop, and those classify differently (add is
        // Safe, drop is Destructive), so emitting them independently lets a Safe-ceiling run apply
        // half a rename — adding the new constraint while keeping the old one. They are paired up by
        // shape below and emitted as one atomic change instead.
        // array-key, not string: a numerically-named constraint (`1`, which is how some engines
        // name an unnamed one) becomes an int key, so every read back out is cast — see above.
        /** @var array<array-key, ForeignKeyDefinition> $pendingAdds */
        $pendingAdds = [];
        /** @var array<array-key, LiveForeignKey> $pendingDrops */
        $pendingDrops = [];

        foreach ($desiredFks as $name => $fk) {
            $name = (string) $name; // numeric-string array key -> int; see above
            $liveFk = $live->foreignKeys[$name] ?? null;
            if (null === $liveFk) {
                $pendingAdds[$name] = $fk;
                continue;
            }
            if (self::liveFkShape($liveFk) !== self::desiredFkShape($fk)) {
                $drop = $this->emitter->dropForeignKey($table, $name);
                if (null === $drop) {
                    $changes[] = $this->manual($table, $name, 'foreign key differs but this engine cannot drop an FK in place (table rebuild — Manual in v0.1)');
                    continue;
                }
                $add = $this->emitter->addForeignKey($table, $fk);
                if ([] === $add) {
                    $changes[] = $this->manual($table, $name, 'foreign key differs but this engine cannot add an FK in place (table rebuild — Manual in v0.1)');
                    continue;
                }

                // One change carrying both statements, not a drop *and* an add. Emitted separately
                // they could be authorised separately, and a ceiling that admitted the drop alone
                // would leave the table with no constraint at all — the net effect here is a
                // constraint *replacement*, so that is what the plan says.
                $changes[] = new PlannedChange(
                    $table,
                    'replace_foreign_key',
                    $name,
                    ChangeClass::Safe,
                    [...$drop, ...$add],
                    'foreign key shape differs — dropped and re-added',
                    mayRejectExistingRows: true,
                );
            }
        }
        if (!$partiallyDeclared) {
            foreach ($live->foreignKeys as $name => $liveFk) {
                $name = (string) $name; // numeric-string array key -> int; see above
                if (isset($desired->unmanaged[SchemaObjectKind::ForeignKey->value][$name])) {
                    continue; // see Attribute\Unmanaged
                }
                if (!isset($desiredFks[$name])) {
                    $pendingDrops[$name] = $liveFk;
                }
            }
        }

        foreach ($pendingAdds as $newName => $fk) {
            $newName = (string) $newName; // numeric-string array key -> int; see above
            $oldName = self::soleShapeMatch($fk, $pendingDrops);
            if (null === $oldName) {
                $changes[] = $this->fkAdd($table, $newName, $fk);
                continue;
            }
            unset($pendingDrops[$oldName]);
            $changes[] = $this->fkRename($table, $oldName, $newName, $fk);
        }

        // Safe, not Destructive: a constraint drop removes no data, and an undeclared foreign key
        // contradicts the declared model rather than merely adding to it — it forbids writes the
        // Records permit. Leaving one in place is drift that silently overrules the schema, and it
        // does real damage: an `ON DELETE CASCADE` left behind by a column rename will happily
        // delete rows nobody meant to reach. A table this library does not fully own is a question
        // of *authority*, which `PartiallyDeclared` answers by skipping this loop entirely.
        foreach (array_keys($pendingDrops) as $name) {
            $name = (string) $name; // numeric-string array key -> int; see above
            $drop = $this->emitter->dropForeignKey($table, $name);
            if (null === $drop) {
                $changes[] = $this->manual($table, $name, 'undeclared live foreign key; this engine cannot drop an FK in place');
                continue;
            }
            $absent = $desired->absent[SchemaObjectKind::ForeignKey->value][$name] ?? null;
            $changes[] = new PlannedChange(
                $table,
                'drop_foreign_key',
                $name,
                ChangeClass::Safe,
                $drop,
                null === $absent ? 'foreign key exists live but is not declared' : 'foreign key '.$absent->describe(),
            );
        }

        return [...$changes, ...$this->diffChecks($table, $desired, $live, $partiallyDeclared)];
    }

    /**
     * CHECK constraints, **by name only**.
     *
     * Deliberately no expression comparison anywhere, because no engine gives the expression back
     * the way it was written — MySQL re-prints it with charset introducers and its own brackets,
     * PostgreSQL adds casts — so a textual comparison cannot separate "the author changed the rule"
     * from "the engine spells it differently". Every available answer to that ambiguity is wrong in
     * one direction or the other: compare, and a correct database reports drift forever; skip, and a
     * corrected rule never reaches a database that already has the old one. (Generated-column
     * expressions demonstrated both, in that order.)
     *
     * The producer sidesteps it instead: an attrecord CHECK name carries a digest of its expression,
     * so an edited expression *is* a differently-named constraint and shows up here as one add and
     * one drop. That makes name-only diffing complete rather than partial — for constraints this
     * package's producer emitted. A hand-written constraint whose body someone edits in place is
     * genuinely invisible, which is the honest limit and is documented as one.
     *
     * @return list<PlannedChange>
     */
    private function diffChecks(string $table, TableSchema $desired, LiveTable $live, bool $partiallyDeclared): array
    {
        $changes = [];

        foreach ($desired->checks as $check) {
            // The definition's own name rather than the map key: a numerically-named constraint
            // would arrive here as an int key, and this one is a string by construction.
            $name = $check->constraintName;
            if (isset($live->checks[$name])) {
                continue;
            }

            $add = $this->emitter->addCheck($table, $check);
            if (null === $add) {
                $changes[] = $this->manual($table, $name, 'CHECK constraint is declared but missing; this engine cannot add one in place (table rebuild)');
                continue;
            }

            // Safe, but flagged: ADD CONSTRAINT validates every existing row, so it can reject the
            // table's current contents. Loudly and atomically — the same shape as ADD UNIQUE on
            // duplicate data or ADD FK on orphans, never a partial application or silent loss.
            $changes[] = new PlannedChange(
                $table,
                'add_check',
                $name,
                ChangeClass::Safe,
                $add,
                'CHECK constraint declared but not present live: '.$check->expression,
                mayRejectExistingRows: true,
            );
        }

        if ($partiallyDeclared) {
            return $changes;
        }

        foreach (array_keys($live->checks) as $name) {
            $name = (string) $name; // numeric-string array key -> int; see above
            if (isset($desired->checks[$name]) || $this->isEngineOwnedCheck($name, $live->checks[$name], $desired, $live)) {
                continue;
            }
            if (isset($desired->unmanaged[SchemaObjectKind::Check->value][$name])) {
                continue; // see Attribute\Unmanaged
            }

            $drop = $this->emitter->dropCheck($table, $name);
            if (null === $drop) {
                $changes[] = $this->manual($table, $name, 'undeclared live CHECK constraint; this engine cannot drop one in place');
                continue;
            }

            // Safe for the same reason an undeclared foreign key is: a constraint drop costs no
            // data, and a rule the Records do not declare *contradicts* them — it forbids writes the
            // model permits. An undeclared index, which forbids nothing, stays Destructive.
            $absent = $desired->absent[SchemaObjectKind::Check->value][$name] ?? null;
            $changes[] = new PlannedChange(
                $table,
                'drop_check',
                $name,
                ChangeClass::Safe,
                $drop,
                null === $absent ? 'CHECK constraint exists live but is not declared' : 'CHECK constraint '.$absent->describe(),
            );
        }

        return $changes;
    }

    /**
     * Whether this CHECK constraint belongs to a *column* rather than to the table — in which case
     * it is not this diff's to converge, and proposing to drop it would remove something nobody
     * asked for.
     *
     * Two kinds, both invisible in the Record because both are consequences of a column type:
     *
     * - **Enum members.** On PostgreSQL and SQLite an `#[Column(ColumnType::Enum)]` has nowhere
     *   native to keep its member list, so the producer writes it into a `chk_<column>_enum` CHECK.
     *   The column diff converges those; dropping one here would take the enum's enforcement with
     *   it.
     * - **MariaDB's JSON validity constraint.** MariaDB has no JSON storage type — a `JSON` column
     *   is LONGTEXT plus an automatic `CHECK (json_valid(col))` named after the column, created by
     *   the engine and reported like any other constraint. Nothing declares it, so a name-only diff
     *   reads it as undeclared and plans a drop; on a schema with any JSON column that is a table
     *   that never converges. Matched on the body as well as the name, so an author's own
     *   constraint that happens to be named after a column is still theirs.
     *
     * Both are matched against the live *and* declared column sets, so a constraint left behind by
     * a column that has since been dropped is recognised too.
     */
    private function isEngineOwnedCheck(string $name, string $body, TableSchema $desired, LiveTable $live): bool
    {
        foreach ([array_keys($desired->columns), array_keys($live->columns)] as $columnNames) {
            foreach ($columnNames as $column) {
                $column = (string) $column;
                if (ColumnDefinition::enumCheckConstraintName($column) === $name) {
                    return true;
                }
                if ($column === $name && 1 === preg_match('/^\s*json_valid\s*\(/i', $body)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<PlannedChange> */
    private function diffColumn(string $table, ColumnDefinition $col, LiveTable $live): array
    {
        $desiredNorm = $this->normalizer->normalizeDesired($col);
        $liveNorm = $this->normalizer->normalizeLive($live->columns[$col->name]);

        if ($desiredNorm->isUnsure() || $liveNorm->isUnsure()) {
            return [$this->manual($table, $col->name, (string) ($desiredNorm->unsureReason ?? $liveNorm->unsureReason))];
        }
        \assert(null !== $desiredNorm->tuple && null !== $liveNorm->tuple);
        $desired = $desiredNorm->tuple;
        $liveTuple = $liveNorm->tuple;

        $facets = $desired->diffFacets($liveTuple);
        if ([] === $facets) {
            return [];
        }

        // A changed generation expression is Assisted, not Manual: the statement is a plain MODIFY
        // and the emitter has everything it needs, but rebuilding a generated column across a
        // populated table is a decision, not a policy. Both spellings go in the reason so an
        // operator can see *what* differs — and so a rewrite that is not a real change, one the
        // normalizer failed to absorb, is recognisable at a glance rather than chased.
        if (\in_array('generated', $facets, true)) {
            $statements = $this->emitter->modifyColumn($table, $col, $desired, $facets);
            if (null === $statements) {
                return [$this->manual($table, $col->name, 'generation expression differs, and this engine has no in-place column modification (rebuild — Manual)')];
            }

            return [new PlannedChange(
                $table,
                'modify_column',
                $col->name,
                ChangeClass::Assisted,
                $statements,
                sprintf(
                    'generation expression differs — declared "%s", live "%s"',
                    $desired->generated ?? '',
                    $liveTuple->generated ?? '',
                ),
            )];
        }
        // Auto-increment stays Manual: unlike the expression above there is no single statement
        // that adopts it safely — the right move depends on the sequence's current value.
        if (\in_array('autoIncrement', $facets, true)) {
            return [$this->manual($table, $col->name, 'auto-increment drift — never auto-applied (facets: '.implode(', ', $facets).')')];
        }

        $statements = $this->emitter->modifyColumn($table, $col, $desired, $facets);
        if (null === $statements) {
            return [$this->manual($table, $col->name, 'column differs ('.implode(', ', $facets).') but this engine has no in-place column modification (rebuild — Manual in v0.1)')];
        }

        [$class, $mayReject] = $this->classifyModification($facets, $desired, $liveTuple);
        $reason = 'column drifted: '.implode(', ', $facets);

        return [new PlannedChange($table, 'modify_column', $col->name, $class, $statements, $reason, $mayReject)];
    }

    /**
     * Classify a column modification from its drifted facets (the design contract §3.1): pure
     * widenings and default changes are Safe; nullable tightening and any narrowing/conversion is
     * Destructive (a failed conversion aborts loudly — nothing is ever silently coerced by *this*
     * package; engine-level coercion is exactly why the opt-in exists).
     *
     * @param list<string> $facets
     *
     * @return array{0: ChangeClass, 1: bool}
     */
    private function classifyModification(array $facets, ColumnTuple $desired, ColumnTuple $live): array
    {
        $class = ChangeClass::Safe;
        $mayReject = false;

        foreach ($facets as $facet) {
            switch ($facet) {
                case 'default':
                    break; // metadata-only, always Safe
                case 'nullable':
                    if ($desired->nullable) {
                        break; // loosening
                    }
                    $mayReject = true; // tightening: existing NULLs reject the ALTER
                    $class = ChangeClass::Destructive;
                    break;
                case 'length':
                    if (null !== $desired->length && null !== $live->length && $desired->length >= $live->length && $desired->type === $live->type) {
                        break; // VARCHAR(64) -> VARCHAR(191): pure widening
                    }
                    $class = ChangeClass::Destructive;
                    break;
                case 'precision':
                case 'scale':
                    if (self::dimensionsWiden($desired, $live)) {
                        break;
                    }
                    $class = ChangeClass::Destructive;
                    break;
                case 'type':
                    if (self::intWidens($live->type, $desired->type) && $desired->unsigned === $live->unsigned) {
                        break; // SMALLINT -> INT etc., same signedness
                    }
                    $class = ChangeClass::Destructive;
                    break;
                case 'members':
                    if (null !== $desired->members && null !== $live->members
                        && $live->members === \array_slice($desired->members, 0, \count($live->members))) {
                        break; // append-only member growth
                    }
                    $class = ChangeClass::Destructive;
                    break;
                default: // unsigned flips and anything unforeseen
                    $class = ChangeClass::Destructive;
                    break;
            }
        }

        return [$class, $mayReject];
    }

    /**
     * Whether a live index is a foreign key's supporting index rather than schema drift.
     *
     * Name matching alone is not enough. MySQL names the index it creates after the constraint, but
     * the two then have independent lifetimes: dropping one FK can leave *its* index as the only
     * one covering the column, still required by a different FK on the same column. The index then
     * matches no FK by name while remaining load-bearing, and `DROP INDEX` is refused (error 1553).
     *
     * So an index also counts as plumbing when it is non-unique and its leading columns are exactly
     * some FK's local columns — the shape an engine creates to support that FK. The cost is that a
     * genuinely operator-added index on precisely an FK's columns is never proposed for dropping;
     * that is the fail-safe direction, and index drops are opt-in anyway.
     */
    private static function isForeignKeyPlumbing(LiveTable $live, string $indexName): bool
    {
        if (isset($live->foreignKeys[$indexName])) {
            return true;
        }
        $index = $live->indexes[$indexName] ?? null;
        if (null === $index || $index->unique) {
            return false;
        }
        foreach ($live->foreignKeys as $fk) {
            if (\array_slice($index->columns, 0, \count($fk->localColumns)) === $fk->localColumns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can every value the live column accepts still fit after the change?
     *
     * Precision and scale have to be judged **together**, because scale is carved *out of*
     * precision: `DECIMAL(10,2) -> DECIMAL(10,4)` keeps ten digits but moves two across the point,
     * so the integer range shrinks from eight digits to six and any value ≥ 1,000,000 is rejected.
     * Judged apart, that reads as "scale grew, precision unchanged" — widening — and would be
     * applied unattended at the `Safe` ceiling.
     *
     * So both must hold: the fractional digits must not shrink, and neither must the integer digits
     * they leave behind.
     *
     * A null dimension is **zero, not unknown**. Both normalizers deliberately collapse an explicit
     * 0 to null so that `datetime` and `datetime(0)` compare equal — which makes `datetime ->
     * datetime(6)` a widening (0 → 6 fractional digits, every stored value preserved), where a
     * null-means-unknown reading classified it Destructive.
     */
    private static function dimensionsWiden(ColumnTuple $desired, ColumnTuple $live): bool
    {
        $desiredScale = $desired->scale ?? 0;
        $liveScale = $live->scale ?? 0;

        return $desiredScale >= $liveScale
            && (($desired->precision ?? 0) - $desiredScale) >= (($live->precision ?? 0) - $liveScale);
    }

    private static function intWidens(string $from, string $to): bool
    {
        $order = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 5];

        return isset($order[$from], $order[$to]) && $order[$to] >= $order[$from];
    }

    private static function canonAction(string $action): string
    {
        // NO ACTION and RESTRICT are equivalent in effect on these engines; engines report either.
        return 'NO ACTION' === strtoupper($action) ? 'RESTRICT' : strtoupper($action);
    }

    private function fkAdd(string $table, string $name, ForeignKeyDefinition $fk): PlannedChange
    {
        $statements = $this->emitter->addForeignKey($table, $fk);
        if ([] === $statements) {
            // SQLite cannot ADD a foreign key in place (table rebuild — Manual in v0.1).
            return $this->manual($table, $name, 'foreign key missing but this engine cannot add an FK in place (table rebuild — Manual in v0.1)');
        }

        return new PlannedChange(
            $table,
            'add_foreign_key',
            $name,
            ChangeClass::Safe,
            $statements,
            'foreign key missing',
            mayRejectExistingRows: true,
        );
    }

    /**
     * The identity of a foreign key *as a constraint*, independent of what it is called: local
     * columns, target, and referential actions. Two keys with the same shape enforce the same rule.
     *
     * @return array{0: list<string>, 1: string, 2: list<string>, 3: string, 4: string}
     */
    private static function desiredFkShape(ForeignKeyDefinition $fk): array
    {
        return [
            [$fk->localColumn],
            $fk->targetTableName(),
            [$fk->targetColumnName()],
            self::canonAction($fk->onDelete->value),
            self::canonAction($fk->onUpdate->value),
        ];
    }

    /** @return array{0: list<string>, 1: string, 2: list<string>, 3: string, 4: string} */
    private static function liveFkShape(LiveForeignKey $fk): array
    {
        return [
            $fk->localColumns,
            $fk->referencedTable,
            $fk->referencedColumns,
            self::canonAction($fk->onDelete),
            self::canonAction($fk->onUpdate),
        ];
    }

    /**
     * Which live index a desired one is a rename *of*, or null when it is genuinely new.
     *
     * A declaration wins outright and is the only thing that survives renaming an index **and**
     * changing its columns in the same release — at that point nothing about the two shapes says
     * they are related. Failing that, an identically-shaped orphan on each side is taken as the
     * rename, which covers the ordinary case without anyone having to declare anything.
     *
     * Inferring is safe here in a way it is not for a column: the outcome either way is one index
     * of the desired shape, so guessing wrong costs a rebuild rather than data. A column rename is
     * never inferred, for exactly the opposite reason.
     *
     * @param array{columns: list<string>, unique: bool} $spec
     * @param array<array-key, LiveIndex>                $candidates
     */
    private static function indexRenameSource(TableSchema $desired, string $newName, array $spec, array $candidates): ?string
    {
        $declared = $desired->indexRenames[$newName] ?? null;
        if (null !== $declared) {
            // A declaration that names something not on offer — already applied on an earlier
            // converge, or simply stale — is not an error: the index it describes is gone, which is
            // what the declaration was asking for.
            return isset($candidates[$declared->from]) ? $declared->from : null;
        }

        $found = null;
        foreach ($candidates as $name => $live) {
            if ($live->columns !== $spec['columns'] || $live->unique !== $spec['unique']) {
                continue;
            }
            if (null !== $found) {
                return null; // ambiguous — two identically-shaped orphans name no single source
            }
            $found = (string) $name;
        }

        return $found;
    }

    /**
     * An index rename, emitted as **one** change so a ceiling decision applies to all of it.
     *
     * Every engine but SQLite renames in the catalogue, which is instant and touches no rows. The
     * fallback builds the new index before dropping the old one — that order matters: it costs a
     * transient period of double storage, and buys never leaving the table unindexed if the run
     * stops between the two statements.
     *
     * @param array{columns: list<string>, unique: bool} $spec
     */
    private function indexRename(string $table, LiveIndex $from, string $to, array $spec, bool $declared): PlannedChange
    {
        $why = $declared ? 'declared rename' : 'same shape, so read as a rename';
        // Renaming in the catalogue keeps the *live* columns, so it is only the right statement
        // when those already match. A declared rename may also have changed shape — the case the
        // shape heuristic cannot see and the declaration exists for — and there the index has to be
        // rebuilt however cheap a rename would have been.
        $sameShape = $from->columns === $spec['columns'] && $from->unique === $spec['unique'];
        $native = $sameShape ? $this->emitter->renameIndex($table, $from->name, $to, $spec['unique']) : null;
        if (null !== $native) {
            return new PlannedChange(
                $table,
                'rename_index',
                $to,
                ChangeClass::Safe,
                $native,
                sprintf('%s from "%s" (%s) — catalogue-only on this engine', $spec['unique'] ? 'unique key' : 'index', $from->name, $why),
            );
        }

        return new PlannedChange(
            $table,
            'rename_index',
            $to,
            ChangeClass::Safe,
            [
                ...$this->emitter->createIndex($table, $to, $spec['columns'], $spec['unique']),
                ...$this->emitter->dropIndex($table, $from->name),
            ],
            sprintf(
                '%s from "%s" (%s) — %s, so it is rebuilt then dropped',
                $spec['unique'] ? 'unique key' : 'index',
                $from->name,
                $why,
                $sameShape ? 'this engine cannot rename' : 'its shape changed too',
            ),
            mayRejectExistingRows: $spec['unique'],
        );
    }

    /**
     * The desired definitions of every **live** generated column whose expression names `$oldName`.
     *
     * Read from the live expression rather than the desired one, because that is what the engine is
     * actually holding: the desired expression already names the new column, so it says nothing
     * about what the rename will collide with. The definitions returned are the *desired* ones,
     * since those are what a rebuild must put back.
     *
     * A column no longer declared is skipped — it is being dropped anyway, and rebuilding it on the
     * way past would be work in the wrong direction.
     *
     * @return list<ColumnDefinition>
     */
    private static function generatedDependents(TableSchema $desired, LiveTable $live, string $oldName): array
    {
        $dependents = [];
        foreach ($live->columns as $liveName => $liveCol) {
            $liveName = (string) $liveName; // numeric-string array key -> int; see above
            if (null === $liveCol->generationExpression || $liveName === $oldName) {
                continue;
            }
            if (!self::expressionReferences($liveCol->generationExpression, $oldName)) {
                continue;
            }
            $wanted = $desired->columns[$liveName] ?? null;
            if (null !== $wanted && $wanted->isGenerated) {
                $dependents[] = $wanted;
            }
        }

        return $dependents;
    }

    /**
     * Whether a generation expression names `$column`.
     *
     * Word-bounded, so `qty` does not match inside `qty_received` — `_` is a word character, which
     * is what makes the plain boundary sufficient. Backticks are word boundaries themselves, so the
     * quoted and bare forms both match without a second pattern.
     *
     * A name appearing inside a string literal would match too. That costs an unnecessary rebuild of
     * a virtual column, never a wrong result, and paying it is the right side to err on: the failure
     * it guards against is an `ALTER` the engine refuses outright.
     */
    private static function expressionReferences(string $expression, string $column): bool
    {
        return 1 === preg_match('/\\b'.preg_quote($column, '/').'\\b/i', $expression);
    }

    /**
     * Which live columns a declared rename is going to rebuild, so the main column loop can leave
     * them alone — the rebuild re-adds each from the same desired definition it would diff against.
     *
     * @return array<string, true>
     */
    private static function columnsRebuiltByRename(TableSchema $desired, LiveTable $live): array
    {
        $rebuilt = [];
        foreach ($desired->columns as $colName => $col) {
            $from = $col->renamedFrom;
            if (null === $from || isset($live->columns[$colName]) || !isset($live->columns[$from]) || isset($desired->columns[$from])) {
                continue;
            }
            foreach (self::generatedDependents($desired, $live, $from) as $dependent) {
                $rebuilt[$dependent->name] = true;
            }
        }

        return $rebuilt;
    }

    /**
     * The one live constraint that `$fk` is a rename of, or null.
     *
     * Null when nothing matches — an ordinary add — and **also when more than one does**. Two live
     * keys of identical shape are already redundant with each other, so there is no fact of the
     * matter about which one was renamed; guessing would drop an arbitrary constraint. Falling back
     * to plain add/drop keeps that decision with the operator.
     *
     * @param array<array-key, LiveForeignKey> $candidates
     */
    private static function soleShapeMatch(ForeignKeyDefinition $fk, array $candidates): ?string
    {
        $shape = self::desiredFkShape($fk);
        $found = null;
        foreach ($candidates as $name => $live) {
            if (self::liveFkShape($live) !== $shape) {
                continue;
            }
            if (null !== $found) {
                return null; // ambiguous
            }
            $found = (string) $name;
        }

        return $found;
    }

    /**
     * A constraint rename, emitted as **one** change so a ceiling decision applies to all of it.
     *
     * PostgreSQL renames in the catalogue — instant, no validation — so it is `Safe`. MySQL and
     * MariaDB have no `RENAME CONSTRAINT`, so the same outcome costs an `ADD FOREIGN KEY` that
     * validates every existing row under a metadata lock, plus a `DROP`: real work on a large table,
     * and not something to do unattended at boot, hence `Destructive`.
     *
     * The fallback adds **before** dropping, so the column is never left unconstrained — the window
     * a drop-then-add ordering would open.
     */
    private function fkRename(string $table, string $from, string $to, ForeignKeyDefinition $fk): PlannedChange
    {
        $native = $this->emitter->renameForeignKey($table, $from, $to);
        if (null !== $native) {
            return new PlannedChange(
                $table,
                'rename_foreign_key',
                $to,
                ChangeClass::Safe,
                $native,
                sprintf('foreign key renamed from "%s" (same shape) — catalogue-only on this engine', $from),
            );
        }

        $add = $this->emitter->addForeignKey($table, $fk);
        $drop = $this->emitter->dropForeignKey($table, $from);
        if ([] === $add || null === $drop) {
            return $this->manual($table, $to, sprintf('foreign key renamed from "%s" but this engine cannot rename, add or drop an FK in place (table rebuild — Manual)', $from));
        }

        return new PlannedChange(
            $table,
            'rename_foreign_key',
            $to,
            ChangeClass::Safe,
            [...$add, ...$drop],
            sprintf('foreign key renamed from "%s" (same shape); no RENAME CONSTRAINT on this engine, so it is added then dropped', $from),
            mayRejectExistingRows: true,
        );
    }

    private function manual(string $table, string $subject, string $reason): PlannedChange
    {
        return new PlannedChange($table, 'manual', $subject, ChangeClass::Manual, [], $reason);
    }
}
