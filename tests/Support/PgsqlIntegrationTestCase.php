<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\PgsqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/** PostgreSQL base: own database `attrecord_migrations_test`, created via the `postgres` maintenance DB. */
abstract class PgsqlIntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;
    protected static PdoDbSession $session;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && '' !== $v) ? $v : $d;
        $host = $env('PGSQL_HOST', '127.0.0.1');
        $port = $env('PGSQL_PORT', '5432');
        $name = $env('PGSQL_DB', 'attrecord_migrations_test');
        $user = $env('PGSQL_USER', 'postgres');
        $pass = $env('PGSQL_PASS', 'postgres');

        try {
            $bootstrap = new \PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            /** @psalm-var mixed $exists */
            $exists = $bootstrap->query("SELECT 1 FROM pg_database WHERE datname = '{$name}'")->fetchColumn();
            if (false === $exists) {
                $bootstrap->exec("CREATE DATABASE \"{$name}\"");
            }
            static::$pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            self::markTestSkipped("PostgreSQL not available ({$e->getMessage()}).\nStart it with: docker compose up -d (in ../attrecord)");
        }

        static::$session = new PdoDbSession(static::$pdo);
        Record::setConnection(new Connection(static::$session, new PgsqlDialect()));
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    protected function setUp(): void
    {
        // Drop every table in the public schema (FK-safe via CASCADE).
        /** @var list<array{0: string}> $tables */
        $tables = static::$pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()')->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as $row) {
            static::$pdo->exec('DROP TABLE IF EXISTS "'.str_replace('"', '""', $row[0]).'" CASCADE');
        }
    }
}
