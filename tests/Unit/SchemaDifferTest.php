<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

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
     * A LiveTable matching DiffBaseRecord exactly (MySQL vocabulary), with overridable parts.
     *
     * @param array<string, LiveColumn>|null     $columns
     * @param array<string, LiveIndex>|null      $indexes
     * @param array<string, LiveForeignKey>|null $fks
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
                'fk_t_ref_id' => new LiveForeignKey('fk_t_ref_id', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
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
        self::assertStringContainsString('ADD CONSTRAINT `fk_t_ref_id` FOREIGN KEY (`ref_id`) REFERENCES `diff_ref` (`id`)', $add->statements[0]);
    }

    public function testNoActionAndRestrictAreEquivalentFkActions(): void
    {
        $fks = ['fk_t_ref_id' => new LiveForeignKey('fk_t_ref_id', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'NO ACTION')];
        self::assertSame([], self::mysqlDiffer()->diffTable(TableSchema::fromClass(DiffBaseRecord::class), self::liveBase(fks: $fks)));
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
            'fk_t_ref_id' => new LiveForeignKey('fk_t_ref_id', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'NO ACTION'),
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
            'fk_t_ref_id' => new LiveForeignKey('fk_t_ref_id', ['ref_id'], 'diff_ref', ['id'], 'SET NULL', 'RESTRICT'),
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
}
