<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Diff\SchemaDiffer;
use Nandan108\AttrecordMigrations\Emit\MysqlAlterEmitter;
use Nandan108\AttrecordMigrations\Emit\PgsqlAlterEmitter;
use Nandan108\AttrecordMigrations\Emit\SqliteAlterEmitter;
use Nandan108\AttrecordMigrations\Live\LiveColumn;
use Nandan108\AttrecordMigrations\Live\LiveForeignKey;
use Nandan108\AttrecordMigrations\Live\LiveIndex;
use Nandan108\AttrecordMigrations\Live\LiveTable;
use Nandan108\AttrecordMigrations\Normalize\MysqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\PgsqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\SqliteColumnNormalizer;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Plan\PlannedChange;
use PHPUnit\Framework\TestCase;

#[Table(name: 'diff_t')]
#[Index('idx_name', columns: ['name'])]
#[ForeignKey(column: 'ref_id', references: 'diff_ref', onDelete: ForeignKeyAction::SetNull)]
final class DiffBaseRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_sku')]
    public string $sku = '';

    #[Column(ColumnType::VarChar, length: 191)]
    public string $name = '';

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty = 0;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $ref_id = null;
}

#[Table(name: 'diff_t')]
final class DiffNotNullNoDefaultRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Int)]
    public int $required_no_default = 0;
}

/** Precision/scale widening cases: a temporal precision and a decimal, on one table. */
#[Table(name: 'diff_t')]
final class DiffDimensionRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $seen_at = null;

    #[Column(ColumnType::Decimal, precision: 12, scale: 2, nullable: true)]
    public ?string $amount = null;
}

/** A generated column, for the changed-expression case. */
#[Table(name: 'diff_t')]
final class DiffGeneratedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $a = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $b = 0;

    #[Column(
        ColumnType::SmallIntUnsigned,
        generatedAs: 'GREATEST(0, CAST(a AS SIGNED) - CAST(b AS SIGNED))',
        generatedMode: GeneratedColumnMode::Virtual,
    )]
    public int $gen = 0;
}

#[Table(name: 'diff_t')]
final class DiffRenameRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64, renamedFrom: 'sku_code')]
    public string $sku = '';
}

/**
 * Differ + classifier unit cases against hand-built LiveTables (no database): change kinds,
 * classifications, fail-safe Manual routing, and the dialect-specific SQL shapes.
 */

/** A table carrying one table-level CHECK, for the constraint-diff cases. */
#[Table(name: 'diff_t')]
#[Check('qty_non_negative', 'qty >= 0')]
final class DiffCheckedRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty = 0;
}

final class SchemaDifferTest extends TestCase
{
    protected function setUp(): void
    {
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    private static function mysqlDiffer(): SchemaDiffer
    {
        $dialect = new MysqlDialect();

        return new SchemaDiffer(new MysqlColumnNormalizer(), new MysqlAlterEmitter($dialect), $dialect);
    }

    /**
     * The FK name attrecord derives for DiffBaseRecord — read from the schema rather than written
     * out, because the derivation changed in attrecord 0.16 (the table prefix now contributes a
     * digest, and an unprefixed table is no longer stripped). This suite supports both, so it must
     * not hard-code either spelling.
     */
    private static function baseFkName(): string
    {
        return TableSchema::fromClass(DiffBaseRecord::class)->foreignKeys[0]->constraintName;
    }

    /**
     * A LiveTable matching DiffBaseRecord exactly (MySQL vocabulary), with overridable parts.
     *
     * @param array<array-key, LiveColumn>|null     $columns
     * @param array<array-key, LiveIndex>|null      $indexes
     * @param array<array-key, LiveForeignKey>|null $fks
     */
    private static function liveBase(?array $columns = null, ?array $indexes = null, ?array $fks = null): LiveTable
    {
        return new LiveTable(
            'diff_t',
            $columns ?? [
                'id'     => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
                'sku'    => new LiveColumn('sku', 'varchar(64)', false, 'NULL', false),
                'name'   => new LiveColumn('name', 'varchar(191)', false, 'NULL', false),
                'qty'    => new LiveColumn('qty', 'smallint(5) unsigned', false, '0', false),
                'ref_id' => new LiveColumn('ref_id', 'bigint(20) unsigned', true, 'NULL', false),
            ],
            ['id'],
            $indexes ?? [
                'uniq_sku' => new LiveIndex('uniq_sku', ['sku'], true),
                'idx_name' => new LiveIndex('idx_name', ['name'], false),
            ],
            $fks ?? [
                self::baseFkName() => new LiveForeignKey(self::baseFkName(), ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
            ],
        );
    }

    /** @param list<PlannedChange> $changes */
    private static function only(array $changes, string $kind): PlannedChange
    {
        $matching = array_values(array_filter($changes, static fn (PlannedChange $c): bool => $kind === $c->kind));
        self::assertCount(1, $matching, $kind.' — got: '.implode(', ', array_map(
            static fn (PlannedChange $c): string => "{$c->kind}({$c->subject}: {$c->reason})",
            $changes,
        )));

        return $matching[0];
    }

    public function testInSyncTableYieldsEmptyPlan(): void
    {
        self::assertSame([], self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase()));
    }

    public function testMissingTableYieldsSafeCreate(): void
    {
        $changes = self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), null);
        $create = self::only($changes, 'create_table');
        self::assertSame(ChangeClass::Safe, $create->class);
        self::assertStringContainsString('CREATE TABLE `diff_t`', $create->statements[0]);
    }

    public function testMissingColumnWithDefaultAddsSafely(): void
    {
        $live = self::liveBase();
        $columns = $live->columns;
        unset($columns['qty']);
        $live = self::liveBase(columns: $columns);

        $add = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live), 'add_column');
        self::assertSame(ChangeClass::Safe, $add->class);
        self::assertSame('qty', $add->subject);
        self::assertStringContainsString('ALTER TABLE `diff_t` ADD COLUMN `qty` SMALLINT UNSIGNED NOT NULL DEFAULT 0', $add->statements[0]);
    }

    public function testNotNullNoDefaultAddIsManual(): void
    {
        $live = new LiveTable('diff_t', [
            'id' => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
        ], ['id'], [], []);

        $manual = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffNotNullNoDefaultRecord::class), $live), 'manual');
        self::assertSame('required_no_default', $manual->subject);
        self::assertSame([], $manual->statements);
        self::assertStringContainsString('NOT NULL', $manual->reason);
    }

    public function testUndeclaredLiveColumnDropIsDestructive(): void
    {
        $columns = self::liveBase()->columns;
        $columns['legacy'] = new LiveColumn('legacy', 'varchar(32)', true, 'NULL', false);
        $drop = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'drop_column');
        self::assertSame(ChangeClass::Destructive, $drop->class);
        self::assertSame(['ALTER TABLE `diff_t` DROP COLUMN `legacy`'], $drop->statements);
    }

    public function testDeclaredRenameIsSafeAndDataPreserving(): void
    {
        $live = new LiveTable('diff_t', [
            'id'       => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
            'sku_code' => new LiveColumn('sku_code', 'varchar(64)', false, 'NULL', false),
        ], ['id'], [], []);

        $changes = self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffRenameRecord::class), $live);
        $rename = self::only($changes, 'rename_column');
        self::assertSame(ChangeClass::Safe, $rename->class);
        self::assertStringContainsString('CHANGE COLUMN `sku_code` `sku` VARCHAR(64)', $rename->statements[0]);
        // No drop+add pair alongside the rename.
        self::assertSame([], array_filter($changes, static fn (PlannedChange $c): bool => \in_array($c->kind, ['add_column', 'drop_column'], true)));
    }

    public function testNullableTighteningIsDestructiveAndMayReject(): void
    {
        $columns = self::liveBase()->columns;
        $columns['name'] = new LiveColumn('name', 'varchar(191)', true, 'NULL', false); // live nullable, desired NOT NULL
        $modify = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'modify_column');
        self::assertSame(ChangeClass::Destructive, $modify->class);
        self::assertTrue($modify->mayRejectExistingRows);
    }

    public function testVarcharWideningIsSafe(): void
    {
        $columns = self::liveBase()->columns;
        $columns['name'] = new LiveColumn('name', 'varchar(64)', false, 'NULL', false); // live narrower than desired 191
        $modify = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'modify_column');
        self::assertSame(ChangeClass::Safe, $modify->class);
        self::assertStringContainsString('MODIFY COLUMN `name` VARCHAR(191)', $modify->statements[0]);
    }

    public function testVarcharNarrowingIsDestructive(): void
    {
        $columns = self::liveBase()->columns;
        $columns['name'] = new LiveColumn('name', 'varchar(255)', false, 'NULL', false); // live wider than desired 191
        $modify = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'modify_column');
        self::assertSame(ChangeClass::Destructive, $modify->class);
    }

    public function testDefaultOnlyDriftIsSafe(): void
    {
        $columns = self::liveBase()->columns;
        $columns['qty'] = new LiveColumn('qty', 'smallint(5) unsigned', false, '5', false); // live default 5, desired 0
        $modify = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'modify_column');
        self::assertSame(ChangeClass::Safe, $modify->class);
    }

    public function testUnparseableLiveTypeIsManualNeverGuessed(): void
    {
        $columns = self::liveBase()->columns;
        $columns['name'] = new LiveColumn('name', 'geometrycollection', false, null, false);
        $manual = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(columns: $columns)), 'manual');
        self::assertStringContainsString('geometrycollection', $manual->reason);
    }

    public function testPrimaryKeyMismatchIsManual(): void
    {
        $live = self::liveBase();
        $live = new LiveTable($live->name, $live->columns, ['id', 'sku'], $live->indexes, $live->foreignKeys);
        $manual = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live), 'manual');
        self::assertStringContainsString('primary key differs', $manual->reason);
    }

    public function testMissingUniqueKeyIsSafeButMayReject(): void
    {
        $indexes = self::liveBase()->indexes;
        unset($indexes['uniq_sku']);
        $create = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(indexes: $indexes)), 'create_index');
        self::assertSame(ChangeClass::Safe, $create->class);
        self::assertTrue($create->mayRejectExistingRows);
        self::assertSame(['CREATE UNIQUE INDEX `uniq_sku` ON `diff_t` (`sku`)'], $create->statements);
    }

    public function testUndeclaredLiveIndexDropIsDestructive(): void
    {
        $indexes = self::liveBase()->indexes;
        $indexes['idx_dba_added'] = new LiveIndex('idx_dba_added', ['qty'], false);
        $drop = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(indexes: $indexes)), 'drop_index');
        self::assertSame(ChangeClass::Destructive, $drop->class);
        self::assertStringContainsString('operator-added', $drop->reason);
    }

    public function testIndexSupportingAForeignKeyIsNotDroppedEvenUnderAnotherName(): void
    {
        // MySQL names an FK's supporting index after the constraint, but the two have independent
        // lifetimes: after one FK is dropped, its index can be the only one covering the column and
        // still be required by another FK. Dropping it is refused by the engine — so an index whose
        // leading columns are an FK's columns counts as plumbing whatever it is called.
        $indexes = self::liveBase()->indexes;
        $indexes['fk_since_dropped'] = new LiveIndex('fk_since_dropped', ['ref_id'], false);

        self::assertSame([], self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(indexes: $indexes)));
    }

    public function testUndeclaredUniqueIndexOnForeignKeyColumnIsStillDropped(): void
    {
        // A *unique* index is never something an engine adds to support an FK, so the plumbing
        // exemption must not swallow it — it is a real constraint the Records do not declare.
        $indexes = self::liveBase()->indexes;
        $indexes['uniq_ref'] = new LiveIndex('uniq_ref', ['ref_id'], true);

        $drop = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(indexes: $indexes)), 'drop_index');
        self::assertSame(ChangeClass::Destructive, $drop->class);
        self::assertSame('uniq_ref', $drop->subject);
    }

    public function testMissingForeignKeyAddsSafelyWithMayReject(): void
    {
        $add = self::only(self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(fks: [])), 'add_foreign_key');
        self::assertSame(ChangeClass::Safe, $add->class);
        self::assertTrue($add->mayRejectExistingRows);
        self::assertStringContainsString('ADD CONSTRAINT `'.self::baseFkName().'` FOREIGN KEY (`ref_id`) REFERENCES `diff_ref` (`id`)', $add->statements[0]);
    }

    public function testNoActionAndRestrictAreEquivalentFkActions(): void
    {
        $fks = [self::baseFkName() => new LiveForeignKey(self::baseFkName(), ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'NO ACTION')];
        self::assertSame([], self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(fks: $fks)));
    }

    // ---- precision / scale ----
    //
    // Scale is carved out of precision, so the two must be judged together. A null dimension is
    // zero, not unknown: both normalizers collapse an explicit 0 to null so `datetime` and
    // `datetime(0)` compare equal.

    /** The dimension table with `seen_at` and `amount` overridden to the given live SQL types. */
    private static function liveDims(string $seenAt, string $amount): LiveTable
    {
        return new LiveTable('diff_t', [
            'id'      => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
            'seen_at' => new LiveColumn('seen_at', $seenAt, true, null, false),
            'amount'  => new LiveColumn('amount', $amount, true, null, false),
        ], ['id'], [], []);
    }

    /** @return array<string, ChangeClass> subject => class */
    private static function dimClasses(LiveTable $live): array
    {
        $out = [];
        foreach (self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffDimensionRecord::class), $live) as $c) {
            if ('modify_column' === $c->kind) {
                $out[$c->subject] = $c->class;
            }
        }

        return $out;
    }

    public function testGainingFractionalSecondsIsSafe(): void
    {
        // datetime -> datetime(6): every stored value survives, it just gains capacity. Live
        // precision is null, which means 0 here — reading it as "unknown" made this Destructive.
        $classes = self::dimClasses(self::liveDims('datetime', 'decimal(12,2)'));
        self::assertSame(ChangeClass::Safe, $classes['seen_at'] ?? null);
        self::assertArrayNotHasKey('amount', $classes, 'the decimal did not drift');
    }

    public function testLosingFractionalSecondsIsDestructive(): void
    {
        // The mirror: datetime(9)-style precision down to 6 truncates. (Live 7 > desired 6.)
        $classes = self::dimClasses(self::liveDims('datetime(7)', 'decimal(12,2)'));
        self::assertSame(ChangeClass::Destructive, $classes['seen_at'] ?? null);
    }

    public function testDecimalWideningIsSafe(): void
    {
        // decimal(10,2) -> decimal(12,2): two more integer digits, fraction unchanged.
        $classes = self::dimClasses(self::liveDims('datetime(6)', 'decimal(10,2)'));
        self::assertSame(ChangeClass::Safe, $classes['amount'] ?? null);
    }

    public function testDecimalScaleGrowthThatShrinksTheIntegerPartIsDestructive(): void
    {
        // decimal(12,4) -> decimal(12,2) shrinks the fraction — plainly Destructive. The subtler
        // direction is the one judged wrongly when the facets are read apart: growing scale within
        // a fixed precision moves digits across the point, so the integer range shrinks and large
        // values are rejected. Here live decimal(12,0) -> desired decimal(12,2): twelve integer
        // digits become ten.
        $classes = self::dimClasses(self::liveDims('datetime(6)', 'decimal(12,0)'));
        self::assertSame(ChangeClass::Destructive, $classes['amount'] ?? null);
    }

    public function testChangedGenerationExpressionIsAssistedAndCarriesItsSql(): void
    {
        // The statement is known — a plain MODIFY — so this is Assisted, not Manual: an operator
        // who has taken a backup can apply it. Manual would carry no SQL, leaving nothing to press.
        $live = self::liveDims('datetime(6)', 'decimal(12,2)');
        $cols = $live->columns;
        $cols['seen_at'] = new LiveColumn('seen_at', 'datetime(6)', true, null, false);
        $cols['gen'] = new LiveColumn('gen', 'smallint(5) unsigned', true, null, false, 'greatest(0,`a` - `b`)');

        $change = self::only(
            self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffGeneratedRecord::class), new LiveTable('diff_t', [
                'id'  => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
                'a'   => new LiveColumn('a', 'smallint(5) unsigned', false, '0', false),
                'b'   => new LiveColumn('b', 'smallint(5) unsigned', false, '0', false),
                'gen' => $cols['gen'],
            ], ['id'], [], [])),
            'modify_column',
        );

        self::assertSame(ChangeClass::Assisted, $change->class);
        self::assertNotSame([], $change->statements, 'an Assisted change must carry the SQL that applies it');
        self::assertStringContainsString('MODIFY COLUMN', $change->statements[0]);
        // Both spellings are named so an operator can judge whether the difference is real.
        self::assertStringContainsString('declared "', $change->reason);
        self::assertStringContainsString('live "', $change->reason);
    }

    // ---- foreign-key renames ----
    //
    // A constraint rename reaches the differ as an unmatched desired name plus an unmatched live
    // name. Emitted independently those classify differently — add is Safe, drop is Destructive —
    // so a Safe-ceiling run would apply half a rename, adding the new constraint while keeping the
    // old one. They are paired by shape and emitted as one change so a ceiling decision covers all
    // of it.

    /** The base live table with its FK renamed by hand — same shape, different name. */
    private static function liveWithRenamedFk(string $name = 'fk_named_by_hand'): LiveTable
    {
        return self::liveBase(fks: [
            $name => new LiveForeignKey($name, ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
        ]);
    }

    public function testRenamedForeignKeyIsOneDestructiveChangeOnMysql(): void
    {
        $changes = self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveWithRenamedFk());

        $rename = self::only($changes, 'rename_foreign_key');
        self::assertSame(ChangeClass::Destructive, $rename->class, 'ADD FOREIGN KEY validates every row under a lock — not Safe');
        self::assertCount(1, $changes, 'the pair must not also appear as a separate add and drop');

        // Add before drop: the column is never left unconstrained.
        self::assertStringContainsString('ADD CONSTRAINT', $rename->statements[0]);
        self::assertStringContainsString('DROP FOREIGN KEY', $rename->statements[1]);
    }

    public function testRenamedForeignKeyIsSafeOnPgsqlWhichRenamesNatively(): void
    {
        $dialect = new PgsqlDialect();
        $differ = new SchemaDiffer(new PgsqlColumnNormalizer(), new PgsqlAlterEmitter($dialect), $dialect);

        $rename = self::only($differ->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveWithRenamedFk()), 'rename_foreign_key');

        // RENAME CONSTRAINT is a catalogue update — no validation, no rewrite — so it is Safe here
        // even though the identical outcome is Destructive on MySQL.
        self::assertSame(ChangeClass::Safe, $rename->class);
        self::assertCount(1, $rename->statements);
        self::assertStringContainsString('RENAME CONSTRAINT "fk_named_by_hand" TO', $rename->statements[0]);
    }

    public function testRenamedForeignKeyIsManualOnSqlite(): void
    {
        $dialect = new SqliteDialect();
        $differ = new SchemaDiffer(new SqliteColumnNormalizer(), new SqliteAlterEmitter($dialect), $dialect);

        $manual = self::only($differ->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveWithRenamedFk()), 'manual');
        self::assertSame(ChangeClass::Manual, $manual->class);
    }

    public function testAmbiguousShapeMatchIsNotTreatedAsARename(): void
    {
        // Two live keys of identical shape are already redundant with each other, so there is no
        // fact about which one was renamed. Guessing would drop an arbitrary constraint; the differ
        // declines and leaves the decision with the operator as a plain add + drop.
        $live = self::liveBase(fks: [
            'fk_one' => new LiveForeignKey('fk_one', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
            'fk_two' => new LiveForeignKey('fk_two', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
        ]);

        $kinds = array_map(static fn (PlannedChange $c): string => $c->kind, self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live));
        self::assertNotContains('rename_foreign_key', $kinds);
        self::assertContains('add_foreign_key', $kinds);
        self::assertContains('drop_foreign_key', $kinds);
    }

    public function testDifferentShapeIsNotARename(): void
    {
        // Only the *name* may differ for this to be a rename. A different referential action is a
        // genuinely different constraint, and must still be dropped and added on its own terms.
        $live = self::liveBase(fks: [
            'fk_named_by_hand' => new LiveForeignKey('fk_named_by_hand', ['ref_id'], 'diff_ref', ['id'], 'CASCADE', 'RESTRICT'),
        ]);

        $kinds = array_map(static fn (PlannedChange $c): string => $c->kind, self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live));
        self::assertNotContains('rename_foreign_key', $kinds);
        self::assertSame(['add_foreign_key', 'drop_foreign_key'], $kinds);
    }

    // ---- dialect-specific shapes ----

    public function testPgsqlModifyTouchesOnlyDriftedFacets(): void
    {
        $dialect = new PgsqlDialect();
        $differ = new SchemaDiffer(new PgsqlColumnNormalizer(), new PgsqlAlterEmitter($dialect), $dialect);
        $live = new LiveTable('diff_t', [
            'id'     => new LiveColumn('id', 'int8', false, null, true),
            'sku'    => new LiveColumn('sku', 'varchar(64)', false, 'NULL', false),
            'name'   => new LiveColumn('name', 'varchar(64)', true, 'NULL', false), // narrower + nullable
            'qty'    => new LiveColumn('qty', 'int2', false, '0', false),
            'ref_id' => new LiveColumn('ref_id', 'int8', true, null, false),
        ], ['id'], [
            'uniq_sku' => new LiveIndex('uniq_sku', ['sku'], true),
            'idx_name' => new LiveIndex('idx_name', ['name'], false),
        ], [
            self::baseFkName() => new LiveForeignKey(self::baseFkName(), ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'NO ACTION'),
        ]);

        $modify = self::only($differ->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live), 'modify_column');
        self::assertSame('name', $modify->subject);
        $sql = $modify->statements[0];
        self::assertStringContainsString('ALTER COLUMN "name" TYPE VARCHAR(191)', $sql);
        self::assertStringContainsString('ALTER COLUMN "name" SET NOT NULL', $sql);
        self::assertStringNotContainsString('DEFAULT', $sql, 'default did not drift — must not be touched');
    }

    public function testSqliteColumnModificationIsManual(): void
    {
        $dialect = new SqliteDialect();
        $differ = new SchemaDiffer(new SqliteColumnNormalizer(), new SqliteAlterEmitter($dialect), $dialect);
        $live = new LiveTable('diff_t', [
            'id'     => new LiveColumn('id', 'integer', false, null, true),
            'sku'    => new LiveColumn('sku', 'text', false, null, false),
            'name'   => new LiveColumn('name', 'text', true, null, false), // nullable drift — needs MODIFY
            'qty'    => new LiveColumn('qty', 'integer', false, '0', false),
            'ref_id' => new LiveColumn('ref_id', 'integer', true, null, false),
        ], ['id'], [
            'uniq_sku' => new LiveIndex('uniq_sku', ['sku'], true),
            'idx_name' => new LiveIndex('idx_name', ['name'], false),
        ], [
            self::baseFkName() => new LiveForeignKey(self::baseFkName(), ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
        ]);

        $manual = self::only($differ->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live), 'manual');
        self::assertSame('name', $manual->subject);
        self::assertStringContainsString('no in-place column modification', $manual->reason);
    }

    public function testSqliteMissingForeignKeyIsManual(): void
    {
        $dialect = new SqliteDialect();
        $differ = new SchemaDiffer(new SqliteColumnNormalizer(), new SqliteAlterEmitter($dialect), $dialect);
        $live = new LiveTable('diff_t', [
            'id'     => new LiveColumn('id', 'integer', false, null, true),
            'sku'    => new LiveColumn('sku', 'text', false, null, false),
            'name'   => new LiveColumn('name', 'text', false, null, false),
            'qty'    => new LiveColumn('qty', 'integer', false, '0', false),
            'ref_id' => new LiveColumn('ref_id', 'integer', true, null, false),
        ], ['id'], [
            'uniq_sku' => new LiveIndex('uniq_sku', ['sku'], true),
            'idx_name' => new LiveIndex('idx_name', ['name'], false),
        ], []);

        $manual = self::only($differ->diffTable(TableSchema::fromClass(DiffBaseRecord::class), $live), 'manual');
        self::assertStringContainsString('cannot add an FK in place', $manual->reason);
    }

    /**
     * A live table carrying a column, an index and a constraint the Record does not declare —
     * the shape of any table whose columns are partly computed at runtime.
     */
    private static function liveWithUndeclaredExtras(bool $withQty = true): LiveTable
    {
        $columns = [
            'id'     => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
            'sku'    => new LiveColumn('sku', 'varchar(64)', false, 'NULL', false),
            'name'   => new LiveColumn('name', 'varchar(191)', false, 'NULL', false),
            'qty'    => new LiveColumn('qty', 'smallint(5) unsigned', false, '0', false),
            'ref_id' => new LiveColumn('ref_id', 'bigint(20) unsigned', true, 'NULL', false),
            // The runtime-added column, and below it the index and constraint that came with it.
            'dim_loc' => new LiveColumn('dim_loc', 'varchar(64)', false, '', false),
        ];
        if (!$withQty) {
            unset($columns['qty']);
        }

        return self::liveBase(
            columns: $columns,
            indexes: [
                'uniq_sku' => new LiveIndex('uniq_sku', ['sku'], true),
                'idx_name' => new LiveIndex('idx_name', ['name'], false),
                // Deliberately NOT led by an FK column: an index whose leading columns are a
                // foreign key's is recognised as that FK's supporting plumbing and is never
                // proposed for dropping anyway, which would prove nothing here.
                'idx_extra' => new LiveIndex('idx_extra', ['name', 'dim_loc'], false),
            ],
            fks: [
                self::baseFkName() => new LiveForeignKey(self::baseFkName(), ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
                'fk_t_extra'       => new LiveForeignKey('fk_t_extra', ['dim_loc'], 'diff_dim', ['code'], 'RESTRICT', 'RESTRICT'),
            ],
        );
    }

    public function testUndeclaredExtrasAreDroppedByDefault(): void
    {
        // The baseline this opt-in departs from: on an ordinary table, live-but-undeclared means
        // someone changed the database behind the schema's back, and it is reported.
        $changes = self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveWithUndeclaredExtras());

        self::assertSame('dim_loc', self::only($changes, 'drop_column')->subject);
        self::assertSame('idx_extra', self::only($changes, 'drop_index')->subject);
        self::assertSame('fk_t_extra', self::only($changes, 'drop_foreign_key')->subject);
    }

    public function testPartiallyDeclaredTableKeepsEverythingItDoesNotDeclare(): void
    {
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffBaseRecord::class),
            self::liveWithUndeclaredExtras(),
            partiallyDeclared: true,
        );

        self::assertSame([], $changes, 'nothing undeclared may be proposed for dropping');
    }

    public function testPartiallyDeclaredStillConvergesWhatItDoesDeclare(): void
    {
        // The opt-out is about *ownership*, not about giving up: a declared column that is
        // missing is still added, on the very same table.
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffBaseRecord::class),
            self::liveWithUndeclaredExtras(withQty: false),
            partiallyDeclared: true,
        );

        self::assertSame('qty', self::only($changes, 'add_column')->subject);
    }

    // --- CHECK constraints -------------------------------------------------

    /**
     * A LiveTable matching DiffCheckedRecord's columns, with whatever CHECK constraints are given.
     *
     * @param array<array-key, string> $checks
     */
    private static function liveChecked(array $checks): LiveTable
    {
        return new LiveTable(
            'diff_t',
            [
                'id'  => new LiveColumn('id', 'bigint(20) unsigned', false, null, true),
                'qty' => new LiveColumn('qty', 'smallint(5) unsigned', false, '0', false),
            ],
            ['id'],
            [],
            [],
            $checks,
        );
    }

    private static function checkName(): string
    {
        return (string) array_key_first(TableSchema::fromClass(DiffCheckedRecord::class)->checks);
    }

    public function testADeclaredCheckMissingLiveIsAddedSafelyButFlagged(): void
    {
        $changes = self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffCheckedRecord::class), self::liveChecked([]));
        $add = self::only($changes, 'add_check');

        self::assertSame(ChangeClass::Safe, $add->class);
        // ADD CONSTRAINT validates the rows already there, so it can reject them — loudly and
        // atomically, the same shape as ADD UNIQUE on duplicate data.
        self::assertTrue($add->mayRejectExistingRows);
        self::assertStringContainsString('ADD CONSTRAINT', $add->statements[0]);
        self::assertStringContainsString('qty >= 0', $add->statements[0]);
    }

    public function testAPresentCheckIsLeftAlone(): void
    {
        // Note the live body is the engine's re-spelling, not the declaration — matching by name is
        // what makes that a non-event rather than permanent drift.
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffCheckedRecord::class),
            self::liveChecked([self::checkName() => '(`qty` >= 0)']),
        );

        self::assertSame([], array_values(array_filter(
            $changes,
            static fn (PlannedChange $c): bool => str_contains($c->kind, 'check'),
        )));
    }

    public function testAnUndeclaredCheckIsDroppedDestructively(): void
    {
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffCheckedRecord::class),
            self::liveChecked([self::checkName() => '(`qty` >= 0)', 'chk_hand_written' => '(`qty` <> 7)']),
        );
        $drop = self::only($changes, 'drop_check');

        self::assertSame(ChangeClass::Destructive, $drop->class);
        self::assertSame('chk_hand_written', $drop->subject);
        self::assertStringContainsString('DROP CONSTRAINT', $drop->statements[0]);
    }

    public function testAPartiallyDeclaredTableKeepsUndeclaredChecks(): void
    {
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffCheckedRecord::class),
            self::liveChecked([self::checkName() => '(`qty` >= 0)', 'chk_someone_elses' => '(`qty` <> 7)']),
            partiallyDeclared: true,
        );

        self::assertSame([], array_values(array_filter(
            $changes,
            static fn (PlannedChange $c): bool => 'drop_check' === $c->kind,
        )));
    }

    public function testMariadbsJsonValidityConstraintIsNotProposedForDropping(): void
    {
        // MariaDB has no JSON storage type: a JSON column is LONGTEXT plus an engine-created
        // `CHECK (json_valid(col))` named after the column. Nothing declares it, so a name-only
        // diff would read it as undeclared and plan a drop — on any schema with a JSON column,
        // a table that never converges.
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffCheckedRecord::class),
            self::liveChecked([self::checkName() => '(`qty` >= 0)', 'qty' => 'json_valid(`qty`)']),
        );

        self::assertSame([], array_values(array_filter(
            $changes,
            static fn (PlannedChange $c): bool => 'drop_check' === $c->kind,
        )));
    }

    public function testAConstraintNamedAfterAColumnIsStillDroppedWhenItIsNotAJsonGuard(): void
    {
        // The json_valid exemption is matched on the body too, so an author's own constraint that
        // happens to carry a column's name stays theirs to converge.
        $changes = self::mysqlDiffer()->diffTable(
            TableSchema::fromClass(DiffCheckedRecord::class),
            self::liveChecked([self::checkName() => '(`qty` >= 0)', 'qty' => '(`qty` <> 13)']),
        );

        self::assertSame('qty', self::only($changes, 'drop_check')->subject);
    }

    public function testSqliteCannotAlterConstraintsSoAMissingCheckIsManual(): void
    {
        $dialect = new SqliteDialect();
        $differ = new SchemaDiffer(new SqliteColumnNormalizer(), new SqliteAlterEmitter($dialect), $dialect);

        $changes = $differ->diffTable(TableSchema::fromClass(DiffCheckedRecord::class), self::liveChecked([]));
        $manual = self::only($changes, 'manual');

        self::assertSame(ChangeClass::Manual, $manual->class);
        self::assertStringContainsString('table rebuild', $manual->reason);
        self::assertSame([], $manual->statements);
    }
}
