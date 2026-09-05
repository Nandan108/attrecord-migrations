<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\Attrecord\Record;
use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\AttrecordMigrations\Tests\Fixtures\GeneratedRenameRecord;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CheckConstraintCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CompositePkCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CyclicSchemaCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\DriftMatrixCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\EvolutionCases;
use Nandan108\AttrecordMigrations\Tests\Support\MysqlIntegrationTestCase;

/** @group Mysql */
final class EvolutionMysqlTest extends MysqlIntegrationTestCase
{
    use CyclicSchemaCases;
    use CheckConstraintCases;
    use CompositePkCases;
    use DriftMatrixCases;
    use EvolutionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new MysqlIntrospector();
    }

    /**
     * MySQL sees every drift the pipeline models — it is the richest of the three engines, so this
     * matrix is the reference the other two are read against.
     */
    #[\Override]
    protected function driftMatrix(): array
    {
        $fk = self::kitchenSinkFkName();
        $modify = static fn (string $spec): string => "ALTER TABLE `mig_kitchen_sink` MODIFY COLUMN {$spec}";

        return [
            'widen_varchar' => [
                'ddl'   => [$modify("`label` VARCHAR(64) NOT NULL DEFAULT ''")],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'narrow_varchar' => [
                'ddl'   => [$modify("`label` VARCHAR(255) NOT NULL DEFAULT ''")],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Destructive,
            ],
            'nullable_tighten' => [
                'ddl'       => [$modify('`qty` SMALLINT UNSIGNED NULL DEFAULT 0')],
                'kinds'     => ['modify_column'],
                'class'     => ChangeClass::Destructive,
                'mayReject' => true,
            ],
            'default_drift' => [
                'ddl'   => [$modify('`qty` SMALLINT UNSIGNED NOT NULL DEFAULT 5')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'int_widen' => [
                'ddl'   => [$modify('`delta` SMALLINT NULL')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'precision_widen' => [
                'ddl'   => [$modify("`price` DECIMAL(8,2) NOT NULL DEFAULT '0.00'")],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'enum_member_append' => [
                // Live is a prefix of desired ('gone' appended) — growth that cannot invalidate a
                // stored value, and the one member change that stays Safe.
                'ddl'   => [$modify("`status` ENUM('draft','live') NOT NULL DEFAULT 'draft'")],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'rename_column' => [
                'ddl'   => ["ALTER TABLE `mig_kitchen_sink` CHANGE COLUMN `label` `label_text` VARCHAR(191) NOT NULL DEFAULT ''"],
                'kinds' => ['rename_column'],
                'class' => ChangeClass::Safe,
            ],
            'index_reshape' => [
                'ddl' => [
                    'DROP INDEX `idx_status_created` ON `mig_kitchen_sink`',
                    'CREATE INDEX `idx_status_created` ON `mig_kitchen_sink` (`status`)',
                ],
                'kinds' => ['replace_index'],
                'class' => ChangeClass::Destructive,
            ],
            'fk_action_change' => [
                'ddl' => [
                    "ALTER TABLE `mig_kitchen_sink` DROP FOREIGN KEY `{$fk}`",
                    "ALTER TABLE `mig_kitchen_sink` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`ref_id`) REFERENCES `mig_ref_targets` (`id`) ON DELETE CASCADE",
                ],
                // One change, not two: the net effect is a constraint replacement, and emitting the
                // halves separately would let a ceiling authorise the drop alone.
                'kinds' => ['replace_foreign_key'],
                'class' => ChangeClass::Safe,
            ],
            'undeclared_fk' => [
                'ddl'   => ['ALTER TABLE `mig_kitchen_sink` ADD CONSTRAINT `fk_extra_ref` FOREIGN KEY (`ref_id`) REFERENCES `mig_ref_targets` (`id`)'],
                'kinds' => ['drop_foreign_key'],
                // Safe: the drop costs no data, and an undeclared foreign key contradicts the
                // declared model rather than adding to it — leaving it is drift that overrules
                // the Records. `PartiallyDeclared` is where "I do not own this table" belongs.
                'class' => ChangeClass::Safe,
                // The FK's supporting index outlives the constraint here and ends up as the only
                // index covering `ref_id` — still required by the FK the Records *do* declare. It
                // must be recognized as plumbing by shape, not by name, or convergence proposes a
                // DROP INDEX the engine rejects (error 1553).
            ],
        ];
    }

    /** MySQL can drop and re-add a primary key in one ALTER. */
    #[\Override]
    protected function dropAndNarrowPrimaryKeySql(string $quotedTable, string $quotedFirstColumn): array
    {
        return ["ALTER TABLE {$quotedTable} DROP PRIMARY KEY, ADD PRIMARY KEY ({$quotedFirstColumn})"];
    }

    /**
     * MySQL-only, because MySQL is the only engine that refuses the plain rename (error 3108) and so
     * the only one whose emitter touches the dependent at all. PostgreSQL and SQLite rewrite the
     * expression themselves, and `EvolutionCases::rename_column` already covers the ordinary rename
     * on all three.
     *
     * The dependent here is **indexed**, which is what makes this a regression test rather than a
     * demonstration: an unindexed one converges under either implementation.
     */
    public function testRenamingAColumnAGeneratedColumnDependsOnKeepsItsIndex(): void
    {
        static::$pdo->exec(
            'CREATE TABLE `mig_gen_rename` ('
            .'`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            ."`value` VARCHAR(64) NOT NULL DEFAULT '', "
            .'`value_uint` BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(`value` AS UNSIGNED)) VIRTUAL, '
            .'PRIMARY KEY (`id`), KEY `idx_value_uint` (`value_uint`)'
            .') ENGINE=InnoDB',
        );
        // Not all-zero, so a check on the recomputed values can actually fail.
        static::$pdo->exec("INSERT INTO `mig_gen_rename` (`value`) VALUES ('10'), ('0'), ('7')");

        $migrator = new SchemaMigrator(Record::connection());
        $plan = $migrator->plan([GeneratedRenameRecord::class]);

        self::assertSame(['rename_column'], array_map(static fn ($c): string => $c->kind, $plan->changes));
        self::assertSame(ChangeClass::Safe, $plan->changes[0]->class, 'a VIRTUAL dependent stores nothing, so nothing is rewritten');
        self::assertCount(1, $plan->changes[0]->statements, 'one ALTER: re-point the dependent, then rename');
        self::assertStringNotContainsString('DROP COLUMN', $plan->changes[0]->statements[0], 'dropping the dependent would take its index with it');

        $migrator->apply($plan);

        self::assertSame(
            [['10', 10], ['0', 0], ['7', 7]],
            static::$pdo->query('SELECT `ident_value`, `value_uint` FROM `mig_gen_rename` ORDER BY `id`')->fetchAll(\PDO::FETCH_NUM),
            'data intact, and the generated column recomputing from the new name',
        );
        self::assertSame(
            ['value_uint'],
            static::$pdo->query(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
                ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mig_gen_rename' AND INDEX_NAME = 'idx_value_uint' "
                .'ORDER BY SEQ_IN_INDEX',
            )->fetchAll(\PDO::FETCH_COLUMN),
            'the index over the dependent survived the rename',
        );

        // The golden invariant, and the assertion that would have caught this on its own: a lost
        // index reads back as a missing declared index and re-plans as a create.
        self::assertTrue($migrator->plan([GeneratedRenameRecord::class])->isEmpty());
    }
}
