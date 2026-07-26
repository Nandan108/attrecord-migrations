<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\SqliteDialect;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\Attrecord\Session\PdoDbSession;
use PHPUnit\Framework\TestCase;

/** SQLite base: fresh in-memory database per test (foreign keys ON, matching production advice). */
abstract class SqliteIntegrationTestCase extends TestCase
{
    protected static \PDO $pdo;
    protected static PdoDbSession $session;

    protected function setUp(): void
    {
        static::$pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        static::$pdo->exec('PRAGMA foreign_keys = ON');
        static::$session = new PdoDbSession(static::$pdo);
        Record::setConnection(new Connection(static::$session, new SqliteDialect()));
        Record::setTablePrefix('');
        TableSchema::clearCache();
    }
}
