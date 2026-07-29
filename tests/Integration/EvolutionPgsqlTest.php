<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\AttrecordMigrations\Introspect\PgsqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CyclicSchemaCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\DriftMatrixCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\EvolutionCases;
use Nandan108\AttrecordMigrations\Tests\Support\PgsqlIntegrationTestCase;

/** @group Pgsql */
final class EvolutionPgsqlTest extends PgsqlIntegrationTestCase
{
    use CyclicSchemaCases;
    use DriftMatrixCases;
    use EvolutionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new PgsqlIntrospector();
    }

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
                // Live lists one fewer member than declared ('gone' added in code but not yet in
                // the database) — the same shape the other two backends test, and the drift that
                // used to be invisible here: the members live in a CHECK constraint, so with that
                // constraint unread the plan came back empty while the database went on rejecting
                // the new value. Growth cannot invalidate a stored value, so it stays Safe.
                'ddl' => [
                    'ALTER TABLE "mig_kitchen_sink" DROP CONSTRAINT "chk_status_enum"',
                    'ALTER TABLE "mig_kitchen_sink" ADD CONSTRAINT "chk_status_enum" CHECK ("status" IN (\'draft\', \'live\'))',
                ],
                'kinds' => ['modify_column'],
                'class' => ChangeClass::Safe,
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
