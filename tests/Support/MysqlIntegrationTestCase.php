<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/**
 * MySQL/MariaDB base: connects to the shared dev server (attrecord's docker-compose services) but
 * uses this package's own database (`attrecord_migrations_test`, auto-created) — never attrecord's
 * `attrecord_test`.
 */
abstract class MysqlIntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;
    protected static PdoDbSession $session;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && '' !== $v) ? $v : $d;
        $host = $env('DB_HOST', '127.0.0.1');
        $port = $env('DB_PORT', '3306');
        $name = $env('DB_NAME', 'attrecord_migrations_test');
        $user = $env('DB_USER', 'root');
        $pass = $env('DB_PASS', 'root');

        try {
            $bootstrap = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $bootstrap->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4");
            static::$pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            self::markTestSkipped("MySQL not available ({$e->getMessage()}).\nStart it with: docker compose up -d (in ../attrecord)");
        }

        static::$session = new PdoDbSession(static::$pdo);
        Record::setConnection(new Connection(static::$session, new MysqlDialect()));
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }

    protected function setUp(): void
    {
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        /** @var list<array{0: string}> $tables */
        $tables = static::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as $row) {
            static::$pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', $row[0]).'`');
        }
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
