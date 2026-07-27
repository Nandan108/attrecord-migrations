<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\SqlDialect;
use Nandan108\AttrecordMigrations\Emit\AlterEmitter;
use Nandan108\AttrecordMigrations\Emit\MysqlAlterEmitter;
use Nandan108\AttrecordMigrations\Emit\PgsqlAlterEmitter;
use Nandan108\AttrecordMigrations\Emit\SqliteAlterEmitter;
use Nandan108\AttrecordMigrations\Introspect\MysqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\PgsqlIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SchemaIntrospector;
use Nandan108\AttrecordMigrations\Introspect\SqliteIntrospector;
use Nandan108\AttrecordMigrations\Normalize\ColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\MysqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\PgsqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\SqliteColumnNormalizer;

/**
 * The per-dialect pipeline bundle: which introspector, normalizer and ALTER emitter serve a given
 * attrecord dialect. Resolved once by {@see SchemaMigrator} from the connection's dialect instance.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface.
 */
final class DialectSupport
{
    private function __construct(
        public readonly SchemaIntrospector $introspector,
        public readonly ColumnNormalizer $normalizer,
        public readonly AlterEmitter $emitter,
    ) {
    }

    public static function for(SqlDialect $dialect): self
    {
        return match (true) {
            $dialect instanceof MysqlDialect  => new self(new MysqlIntrospector(), new MysqlColumnNormalizer(), new MysqlAlterEmitter($dialect)),
            $dialect instanceof PgsqlDialect  => new self(new PgsqlIntrospector(), new PgsqlColumnNormalizer(), new PgsqlAlterEmitter($dialect)),
            $dialect instanceof SqliteDialect => new self(new SqliteIntrospector(), new SqliteColumnNormalizer(), new SqliteAlterEmitter($dialect)),
            default                           => throw new \InvalidArgumentException(
                'No migration support for dialect '.$dialect::class
                .' — the pipeline needs a matching introspector/normalizer/emitter trio.',
            ),
        };
    }
}
