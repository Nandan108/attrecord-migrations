<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
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
                'kinds' => ['drop_index', 'create_index'],
                'class' => ChangeClass::Destructive,
            ],
            'fk_action_change' => [
                'ddl' => [
                    "ALTER TABLE `mig_kitchen_sink` DROP FOREIGN KEY `{$fk}`",
                    "ALTER TABLE `mig_kitchen_sink` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`ref_id`) REFERENCES `mig_ref_targets` (`id`) ON DELETE CASCADE",
                ],
                'kinds' => ['drop_foreign_key', 'add_foreign_key'],
                'class' => ChangeClass::Destructive,
            ],
            'undeclared_fk' => [
                'ddl'   => ['ALTER TABLE `mig_kitchen_sink` ADD CONSTRAINT `fk_extra_ref` FOREIGN KEY (`ref_id`) REFERENCES `mig_ref_targets` (`id`)'],
                'kinds' => ['drop_foreign_key'],
                'class' => ChangeClass::Destructive,
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
}
