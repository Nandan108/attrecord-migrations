<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Integration;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SqliteIntrospector;
use Nandan108\AttrecordMigrations\Plan\ChangeClass;
use Nandan108\AttrecordMigrations\Tests\Fixtures\KitchenSinkRecord;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CompositePkCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\CyclicSchemaCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\DriftMatrixCases;
use Nandan108\AttrecordMigrations\Tests\Integration\Cases\EvolutionCases;
use Nandan108\AttrecordMigrations\Tests\Support\SqliteIntegrationTestCase;

/** @group Sqlite */
final class EvolutionSqliteTest extends SqliteIntegrationTestCase
{
    use CyclicSchemaCases;
    use CompositePkCases;
    use DriftMatrixCases;
    use EvolutionCases;

    protected function introspector(): SchemaIntrospector
    {
        return new SqliteIntrospector();
    }

    /**
     * SQLite is the honest half of the matrix. Two things make it differ from the other engines,
     * and both are pinned here rather than left to the README:
     *
     * 1. **Drift it cannot see.** SQLite stores type *affinity*, not width, precision or member
     *    lists — so a `VARCHAR(255)` where the Record says `VARCHAR(191)` is, to SQLite, the same
     *    `TEXT` column. Those scenarios must plan *nothing*: silence here is correctness (no
     *    false-positive ALTER), and the loss is only the detection the other two engines provide.
     * 2. **Drift it cannot fix in place.** No column modification, no FK add/drop — every one of
     *    those routes to `Manual` with a reason and no SQL, and stays unapplied even at the
     *    Destructive ceiling. (The table-rebuild dance is phase 2.)
     *
     * Drift is injected by rebuilding the table from the producer's own CREATE with one fragment
     * swapped — which is exactly the shape an older install would have.
     */
    #[\Override]
    protected function driftMatrix(): array
    {
        return [
            'widen_varchar' => [
                'ddl'   => $this->rebuiltKitchenSink('"label" TEXT NOT NULL', '"label" VARCHAR(64) NOT NULL'),
                'kinds' => [], // affinity: VARCHAR(64) *is* TEXT here
            ],
            'narrow_varchar' => [
                'ddl'   => $this->rebuiltKitchenSink('"label" TEXT NOT NULL', '"label" VARCHAR(255) NOT NULL'),
                'kinds' => [],
            ],
            'nullable_tighten' => [
                'ddl'   => $this->rebuiltKitchenSink('"qty" INTEGER NOT NULL DEFAULT 0', '"qty" INTEGER DEFAULT 0'),
                'kinds' => ['manual'],
                'class' => ChangeClass::Manual,
            ],
            'default_drift' => [
                'ddl'   => $this->rebuiltKitchenSink('"qty" INTEGER NOT NULL DEFAULT 0', '"qty" INTEGER NOT NULL DEFAULT 5'),
                'kinds' => ['manual'],
                'class' => ChangeClass::Manual,
            ],
            'int_widen' => [
                'ddl'   => $this->rebuiltKitchenSink('"delta" INTEGER', '"delta" SMALLINT'),
                'kinds' => [], // both are INTEGER affinity
            ],
            'precision_widen' => [
                'ddl'   => $this->rebuiltKitchenSink('"price" NUMERIC NOT NULL', '"price" NUMERIC(8,2) NOT NULL'),
                'kinds' => [], // NUMERIC affinity carries no precision
            ],
            'enum_member_append' => [
                // Members live in a CHECK constraint here (no native ENUM) and are now read back
                // out of it, so the drift is *detected*. Applying it is another matter: SQLite has
                // no DROP CONSTRAINT, so widening needs the 12-step table rebuild and the change
                // is Manual. Detected-but-not-applied is the honest outcome, and strictly better
                // than the silence this used to pin.
                'ddl'   => $this->rebuiltKitchenSink("IN ('draft', 'live', 'gone')", "IN ('draft', 'live')"),
                'kinds' => ['manual'],
                'class' => ChangeClass::Manual,
            ],
            'rename_column' => [
                // RENAME COLUMN exists since 3.25 — the one structural fix SQLite does in place.
                'ddl'   => $this->rebuiltKitchenSink('"label" TEXT NOT NULL', '"label_text" TEXT NOT NULL'),
                'kinds' => ['rename_column'],
                'class' => ChangeClass::Safe,
            ],
            'index_reshape' => [
                'ddl'   => $this->rebuiltKitchenSink('("status", "created_at")', '("status")'),
                'kinds' => ['drop_index', 'create_index'],
                'class' => ChangeClass::Destructive,
            ],
            'fk_action_change' => [
                'ddl'   => $this->rebuiltKitchenSink('ON DELETE SET NULL', 'ON DELETE CASCADE'),
                'kinds' => ['manual'],
                'class' => ChangeClass::Manual,
            ],
            'undeclared_fk' => [
                'ddl' => $this->rebuiltKitchenSink(
                    'CONSTRAINT "uniq_sku" UNIQUE ("sku"),',
                    'CONSTRAINT "uniq_sku" UNIQUE ("sku"), CONSTRAINT "fk_extra_ref" FOREIGN KEY ("delta") REFERENCES "mig_ref_targets" ("id"),',
                ),
                'kinds' => ['manual'],
                'class' => ChangeClass::Manual,
            ],
        ];
    }

    /**
     * The producer's own CREATE for the fixture, with one fragment swapped and the table rebuilt —
     * SQLite's only way to *have* a differently-shaped table, and therefore the only way to inject
     * drift for it.
     *
     * @return list<string>
     */
    private function rebuiltKitchenSink(string $from, string $to): array
    {
        $ddl = Record::connection()->dialect->buildCreateTable(TableSchema::fromClass(KitchenSinkRecord::class));
        self::assertStringContainsString($from, $ddl, 'drift fragment must exist in the produced DDL (fixture drifted?)');

        return array_merge(['DROP TABLE "mig_kitchen_sink"'], explode(";\n", str_replace($from, $to, $ddl)));
    }

    /**
     * SQLite cannot alter a primary key at all, so the drift is injected by rebuilding the table —
     * the same 12-step dance the emitter declines to automate.
     */
    #[\Override]
    protected function dropAndNarrowPrimaryKeySql(string $quotedTable, string $quotedFirstColumn): array
    {
        return [
            "DROP TABLE {$quotedTable}",
            "CREATE TABLE {$quotedTable} (\"subject_id\" INTEGER NOT NULL, \"slot_id\" INTEGER NOT NULL, "
                ."\"quantity\" INTEGER NOT NULL DEFAULT 0, PRIMARY KEY ({$quotedFirstColumn}))",
        ];
    }
}
