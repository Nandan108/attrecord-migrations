<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Diff;

use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\ForeignKeyDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\AttrecordMigrations\Emit\AlterEmitter;
use Nandan108\AttrecordMigrations\Live\LiveTable;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;

/**
 * The diff + classifier (arch-migrations.md §4.3): compares one Record's desired `TableSchema`
 * against its introspected {@see LiveTable} and emits classified {@see PlannedChange}s.
 *
 * Fail-safe bias throughout: anything the pipeline is unsure about — an unparseable live type, an
 * ambiguous facet (generated exprs, auto-increment drift), a *changed* PK, an engine
 * without the needed ALTER — becomes a **Manual** change with a reason and no SQL. The differ
 * never guesses an ALTER.
 *
 * Renames are never inferred: only a `#[Column(renamedFrom: 'old')]` declaration produces a
 * data-preserving rename (prior art is unanimous that drop+add similarity inference is a trap).
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
        $liveColumnsClaimed = [];
        foreach ($desired->columns as $colName => $col) {
            if (isset($live->columns[$colName])) {
                $liveColumnsClaimed[$colName] = true;
                foreach ($this->diffColumn($table, $col, $live) as $change) {
                    $changes[] = $change;
                }
                continue;
            }

            // Declared rename: the old column must exist live and must not also be desired.
            $from = $col->renamedFrom;
            if (null !== $from && isset($live->columns[$from]) && !isset($desired->columns[$from])) {
                $liveColumnsClaimed[$from] = true;
                $changes[] = new PlannedChange(
                    $table,
                    'rename_column',
                    $colName,
                    ChangeClass::Safe,
                    $this->emitter->renameColumn($table, $from, $col),
                    "declared rename from '{$from}' (data-preserving)",
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
                $changes[] = $this->manual($table, $colName, 'adding a NOT NULL column without a default: existing rows would have no value — add a default, make it nullable, or backfill via a companion step');
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
            if (!isset($desired->columns[$colName]) && !isset($liveColumnsClaimed[$colName])) {
                $statements = $this->emitter->dropColumn($table, $colName);
                $changes[] = new PlannedChange(
                    $table,
                    'drop_column',
                    $colName,
                    ChangeClass::Destructive,
                    $statements,
                    'column exists live but is not declared — dropping destroys its data',
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

        foreach ($desiredIndexes as $name => $spec) {
            // A declared name that looks numeric arrives as an int (PHP array-key coercion), and
            // every emitter below is typed for a string.
            $name = (string) $name;
            $liveIx = $live->indexes[$name] ?? null;
            if (null === $liveIx) {
                $changes[] = new PlannedChange(
                    $table,
                    'create_index',
                    $name,
                    ChangeClass::Safe,
                    $this->emitter->createIndex($table, $name, $spec['columns'], $spec['unique']),
                    ($spec['unique'] ? 'unique key' : 'index').' missing',
                    mayRejectExistingRows: $spec['unique'],
                );
                continue;
            }
            if ($liveIx->columns !== $spec['columns'] || $liveIx->unique !== $spec['unique']) {
                $changes[] = new PlannedChange(
                    $table,
                    'drop_index',
                    $name,
                    ChangeClass::Destructive,
                    $this->emitter->dropIndex($table, $name),
                    sprintf(
                        'index shape differs (live: [%s]%s, desired: [%s]%s) — recreate',
                        implode(', ', $liveIx->columns),
                        $liveIx->unique ? ' unique' : '',
                        implode(', ', $spec['columns']),
                        $spec['unique'] ? ' unique' : '',
                    ),
                );
                $changes[] = new PlannedChange(
                    $table,
                    'create_index',
                    $name,
                    ChangeClass::Destructive,
                    $this->emitter->createIndex($table, $name, $spec['columns'], $spec['unique']),
                    'recreate with the desired shape',
                    mayRejectExistingRows: $spec['unique'],
                );
            }
        }
        foreach (array_keys($live->indexes) as $name) {
            $name = (string) $name; // numeric-string array key -> int; see above
            if ($partiallyDeclared) {
                break; // see PartiallyDeclared — a computed column brings its own index
            }
            if (!isset($desiredIndexes[$name])) {
                // MySQL implicitly creates a supporting index for every FK constraint (named after
                // it) when none exists — that index is FK plumbing, not drift.
                if (self::isForeignKeyPlumbing($live, $name)) {
                    continue;
                }
                $changes[] = new PlannedChange(
                    $table,
                    'drop_index',
                    $name,
                    ChangeClass::Destructive,
                    $this->emitter->dropIndex($table, $name),
                    'index exists live but is not declared (it may be an operator-added tuning index — dropping requires opt-in)',
                );
            }
        }

        // --- Foreign keys (by constraint name). ---
        /** @var array<array-key, ForeignKeyDefinition> $desiredFks — array-key: see above */
        $desiredFks = [];
        foreach ($desired->foreignKeys as $fk) {
            $desiredFks[$fk->constraintName] = $fk;
        }
        foreach ($desiredFks as $name => $fk) {
            $name = (string) $name; // numeric-string array key -> int; see above
            $liveFk = $live->foreignKeys[$name] ?? null;
            $shape = [[$fk->localColumn], $fk->targetTableName(), [$fk->targetColumnName()], $fk->onDelete->value, $fk->onUpdate->value];
            if (null === $liveFk) {
                $changes[] = $this->fkAdd($table, $name, $fk);
                continue;
            }
            $liveShape = [$liveFk->localColumns, $liveFk->referencedTable, $liveFk->referencedColumns, self::canonAction($liveFk->onDelete), self::canonAction($liveFk->onUpdate)];
            if ($liveShape !== [$shape[0], $shape[1], $shape[2], self::canonAction($shape[3]), self::canonAction($shape[4])]) {
                $drop = $this->emitter->dropForeignKey($table, $name);
                if (null === $drop) {
                    $changes[] = $this->manual($table, $name, 'foreign key differs but this engine cannot drop an FK in place (table rebuild — Manual in v0.1)');
                    continue;
                }
                $changes[] = new PlannedChange($table, 'drop_foreign_key', $name, ChangeClass::Destructive, $drop, 'foreign key shape differs — recreate');
                $changes[] = $this->fkAdd($table, $name, $fk, ChangeClass::Destructive);
            }
        }
        foreach (array_keys($live->foreignKeys) as $name) {
            $name = (string) $name; // numeric-string array key -> int; see above
            if ($partiallyDeclared) {
                break; // see PartiallyDeclared
            }
            if (!isset($desiredFks[$name])) {
                $drop = $this->emitter->dropForeignKey($table, $name);
                if (null === $drop) {
                    $changes[] = $this->manual($table, $name, 'undeclared live foreign key; this engine cannot drop an FK in place');
                    continue;
                }
                $changes[] = new PlannedChange($table, 'drop_foreign_key', $name, ChangeClass::Destructive, $drop, 'foreign key exists live but is not declared');
            }
        }

        return $changes;
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

        // Facets the pipeline refuses to auto-resolve.
        if (\in_array('autoIncrement', $facets, true) || \in_array('generated', $facets, true)) {
            return [$this->manual($table, $col->name, 'auto-increment / generation-expression drift — never auto-applied (facets: '.implode(', ', $facets).')')];
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
     * Classify a column modification from its drifted facets (arch-migrations.md §3.1): pure
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
                    if ($this->dimensionWidens($desired->precision, $live->precision) && $this->dimensionWidens($desired->scale, $live->scale)) {
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

    private function dimensionWidens(?int $desired, ?int $live): bool
    {
        return $desired === $live || (null !== $desired && null !== $live && $desired >= $live);
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

    private function fkAdd(string $table, string $name, ForeignKeyDefinition $fk, ChangeClass $class = ChangeClass::Safe): PlannedChange
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
            $class,
            $statements,
            'foreign key missing',
            mayRejectExistingRows: true,
        );
    }

    private function manual(string $table, string $subject, string $reason): PlannedChange
    {
        return new PlannedChange($table, 'manual', $subject, ChangeClass::Manual, [], $reason);
    }
}
