<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\AttrecordMigrations\Live\LiveColumn;
use Nandan108\AttrecordMigrations\Normalize\ColumnTuple;
use Nandan108\AttrecordMigrations\Normalize\MysqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\PgsqlColumnNormalizer;
use Nandan108\AttrecordMigrations\Normalize\SqliteColumnNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure string-in/tuple-out cases for the live-side normalizers — specifically the engine variants
 * the integration rig cannot produce locally (MySQL 8's unquoted defaults where CI/dev runs
 * MariaDB, foreign-made SQLite tables, malformed types). The mainline paths are pinned by the
 * golden round-trip integration test.
 */
final class ColumnNormalizerTest extends TestCase
{
    private static function live(string $rawType, ?string $rawDefault = null, bool $nullable = false, bool $ai = false, ?string $generated = null): LiveColumn
    {
        return new LiveColumn('c', $rawType, $nullable, $rawDefault, $ai, $generated);
    }

    private static function tuple(MysqlColumnNormalizer | PgsqlColumnNormalizer | SqliteColumnNormalizer $n, LiveColumn $col): ColumnTuple
    {
        $norm = $n->normalizeLive($col);
        self::assertFalse($norm->isUnsure(), (string) $norm->unsureReason);
        \assert(null !== $norm->tuple);

        return $norm->tuple;
    }

    // ---- MySQL family ----

    public function testMysqlIntDisplayWidthsAndUnsignedParse(): void
    {
        $n = new MysqlColumnNormalizer();
        $t = self::tuple($n, self::live('bigint(20) unsigned'));
        self::assertSame(['bigint', true], [$t->type, $t->unsigned]);
        self::assertSame('bool', self::tuple($n, self::live('tinyint(1)'))->type);
        self::assertSame('tinyint', self::tuple($n, self::live('tinyint(1) unsigned'))->type);
        self::assertSame('int', self::tuple($n, self::live('int(11)'))->type);
    }

    public function testMysqlDefaultsQuotedAndUnquotedAgree(): void
    {
        $n = new MysqlColumnNormalizer();
        // MariaDB quotes literal defaults; MySQL 8 reports them bare — both canon to the same value.
        self::assertSame('draft', self::tuple($n, self::live('varchar(10)', "'draft'"))->default);
        self::assertSame('draft', self::tuple($n, self::live('varchar(10)', 'draft'))->default);
        // The *string* 'NULL' (MariaDB no-default) is no default.
        self::assertNull(self::tuple($n, self::live('varchar(10)', 'NULL', nullable: true))->default);
        // Spellings of the timestamp default unify, precision kept.
        self::assertSame('CURRENT_TIMESTAMP(6)', self::tuple($n, self::live('datetime(6)', 'current_timestamp(6)'))->default);
        self::assertSame('CURRENT_TIMESTAMP', self::tuple($n, self::live('datetime', 'current_timestamp()'))->default);
    }

    public function testMysqlEnumMembersParseIncludingEscapedQuotes(): void
    {
        $t = self::tuple(new MysqlColumnNormalizer(), self::live("enum('a','b''c','d')"));
        self::assertSame('enum', $t->type);
        self::assertSame(['a', "b'c", 'd'], $t->members);
    }

    public function testMysqlJsonFoldsToLongtext(): void
    {
        // MariaDB stores JSON as LONGTEXT; both engines' reports land on one family.
        $n = new MysqlColumnNormalizer();
        self::assertSame('longtext', self::tuple($n, self::live('json'))->type);
        self::assertSame('longtext', self::tuple($n, self::live('longtext'))->type);
    }

    public function testMysqlUnknownTypeIsUnsureNeverGuessed(): void
    {
        $norm = (new MysqlColumnNormalizer())->normalizeLive(self::live('geometrycollection'));
        self::assertTrue($norm->isUnsure());
        self::assertStringContainsString('geometrycollection', (string) $norm->unsureReason);
    }

    // ---- PostgreSQL ----

    public function testPgsqlCastSuffixesStripAndBoolKeywordsCanon(): void
    {
        $n = new PgsqlColumnNormalizer();
        self::assertSame('draft', self::tuple($n, self::live('text', "'draft'::text"))->default);
        self::assertSame('0', self::tuple($n, self::live('int2', "'0'::smallint"))->default);
        self::assertNull(self::tuple($n, self::live('varchar(64)', 'NULL::character varying', nullable: true))->default);
        self::assertSame('1', self::tuple($n, self::live('bool', 'true'))->default);
        self::assertSame('CURRENT_TIMESTAMP', self::tuple($n, self::live('timestamp(6)', 'now()'))->default);
    }

    public function testPgsqlBareTimestampIsPrecisionSix(): void
    {
        self::assertSame(6, self::tuple(new PgsqlColumnNormalizer(), self::live('timestamp'))->precision);
    }

    public function testPgsqlUnknownTypeIsUnsure(): void
    {
        self::assertTrue((new PgsqlColumnNormalizer())->normalizeLive(self::live('tsvector'))->isUnsure());
    }

    // ---- SQLite ----

    public function testSqliteAffinityAlgorithmCoversForeignDeclaredTypes(): void
    {
        $n = new SqliteColumnNormalizer();
        // Tables created outside the attrecord producer still normalize, via SQLite's own affinity rules.
        self::assertSame('integer', self::tuple($n, self::live('UNSIGNED BIG INT'))->type);
        self::assertSame('text', self::tuple($n, self::live('VARCHAR(64)'))->type);
        self::assertSame('real', self::tuple($n, self::live('DOUBLE PRECISION'))->type);
        self::assertSame('blob', self::tuple($n, self::live(''))->type);
        self::assertSame('numeric', self::tuple($n, self::live('DECIMAL(10,2)'))->type);
    }

    public function testNumericDefaultCanonMatchesAcrossSpellings(): void
    {
        $n = new SqliteColumnNormalizer();
        self::assertSame('0', self::tuple($n, self::live('NUMERIC', "'0.00'"))->default);
        self::assertSame('1.5', self::tuple($n, self::live('NUMERIC', '1.50'))->default);
        self::assertSame('-5', self::tuple($n, self::live('INTEGER', '-05'))->default);
        // Non-numeric families keep the literal untouched.
        self::assertSame('0.00', self::tuple($n, self::live('TEXT', "'0.00'"))->default);
    }

    public function testGenerationExpressionAbsorbsEngineRespelling(): void
    {
        // The declared form and the engine's stored form of the *same* column must normalize
        // identically, or every generated column reads as permanently drifted. Engines drop a
        // redundant outer bracket pair and quote identifiers; both are absorbed here, which is
        // what lets the expression be compared at all rather than skipped.
        $n = new MysqlColumnNormalizer();
        $declared = self::tuple($n, self::live('tinyint(1)', null, true, generated: '(`closed_at` IS NULL)'));
        $live = self::tuple($n, self::live('tinyint(1)', null, true, generated: 'closed_at is null'));
        self::assertSame($declared->generated, $live->generated);

        // Brackets that are load-bearing survive: they open and close before the end, so the
        // expression is not merely wrapped.
        $paired = self::tuple($n, self::live('int(11)', null, true, generated: '(a)-(b)'));
        self::assertSame('(a)-(b)', $paired->generated);
    }
}
