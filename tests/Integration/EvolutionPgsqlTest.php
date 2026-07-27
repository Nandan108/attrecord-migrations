<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\DriftMatrixCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\EvolutionCases;
use Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase;

/** @group Pgsql */
final class EvolutionPgsqlTest extends PgsqlIntegrationTestCase
{
    use DriftMatrixCases;
    use EvolutionCases;

    /**
     * PostgreSQL matches MySQL on every column facet, with granular `ALTER COLUMN` sub-clauses
     * instead of a whole-column MODIFY. The one blind spot is enum membership: the producer stores
     * it in a CHECK constraint, which v0.1 does not introspect.
     */
    #[\Override]
    protected function driftMatrix(): array
    {
        $fk = self::kitchenSinkFkName();
        $alter = static fn (string $clause): string => "ALTER TABLE \"mig_kitchen_sink\" {$clause}";

        return [
            'widen_varchar' => [
                'ddl'   => [$alter('ALTER COLUMN "label" TYPE VARCHAR(64)')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'narrow_varchar' => [
                'ddl'   => [$alter('ALTER COLUMN "label" TYPE VARCHAR(255)')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Destructive,
            ],
            'nullable_tighten' => [
                'ddl'       => [$alter('ALTER COLUMN "qty" DROP NOT NULL')],
                'kinds'     => ['modify_column'],
                'class'     => ChangeClass::Destructive,
                'mayReject' => true,
            ],
            'default_drift' => [
                'ddl'   => [$alter('ALTER COLUMN "qty" SET DEFAULT 5')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'int_widen' => [
                'ddl'   => [$alter('ALTER COLUMN "delta" TYPE SMALLINT')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'precision_widen' => [
                'ddl'   => [$alter('ALTER COLUMN "price" TYPE NUMERIC(8,2)')],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
            ],
            'enum_member_append' => [
                // Dropping the members' CHECK constraint outright is the loudest member drift there
                // is — and PG still plans nothing, because v0.1 does not model CHECK constraints.
                // The documented blind spot, pinned so it cannot regress into a surprise.
                'ddl' => [<<<'SQL'
                    DO $$
                    DECLARE c text;
                    BEGIN
                        SELECT conname INTO c FROM pg_constraint
                        WHERE conrelid = 'mig_kitchen_sink'::regclass AND contype = 'c' LIMIT 1;
                        IF c IS NOT NULL THEN
                            EXECUTE format('ALTER TABLE mig_kitchen_sink DROP CONSTRAINT %I', c);
                        END IF;
                    END $$;
                    SQL],
                'kinds' => [],
            ],
            'rename_column' => [
                'ddl'   => [$alter('RENAME COLUMN "label" TO "label_text"')],
                'kinds' => ['rename_column'],
                'class' => ChangeClass::Safe,
            ],
            'index_reshape' => [
                'ddl' => [
                    'DROP INDEX "idx_status_created"',
                    'CREATE INDEX "idx_status_created" ON "mig_kitchen_sink" ("status")',
                ],
                'kinds' => ['drop_index', 'create_index'],
                'class' => ChangeClass::Destructive,
            ],
            'fk_action_change' => [
                'ddl' => [
                    $alter("DROP CONSTRAINT \"{$fk}\""),
                    $alter("ADD CONSTRAINT \"{$fk}\" FOREIGN KEY (\"ref_id\") REFERENCES \"mig_ref_targets\" (\"id\") ON DELETE CASCADE"),
                ],
                'kinds' => ['drop_foreign_key', 'add_foreign_key'],
                'class' => ChangeClass::Destructive,
            ],
            'undeclared_fk' => [
                // Unlike MySQL, PG creates no implicit supporting index — one round converges.
                'ddl'   => [$alter('ADD CONSTRAINT "fk_extra_ref" FOREIGN KEY ("ref_id") REFERENCES "mig_ref_targets" ("id")')],
                'kinds' => ['drop_foreign_key'],
                'class' => ChangeClass::Destructive,
            ],
        ];
    }
}
