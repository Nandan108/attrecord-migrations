<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Unit;

use Nandan108\AttrecordMigrations\Normalize\EnumCheckParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every string in the provider below was **captured from a live engine**, not hand-written from
 * the documentation — which is the point of the test. The producer emits one expression
 * (`"col" IN ('a', 'b')`), and PostgreSQL stores four different rewritings of it depending on the
 * column type and the number of members. A parser written against the docs would have handled the
 * first and silently failed the rest, and "silently failed" here means the member list reads as
 * unknown and enum drift goes back to being invisible.
 *
 * Captured from PostgreSQL 16 and SQLite 3 via `pg_get_constraintdef()` / `sqlite_master.sql`.
 */
final class EnumCheckParserTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>|null}> */
    public static function bodies(): iterable
    {
        // --- SQLite: stores the DDL verbatim, so this is the producer's own text ---
        yield 'sqlite verbatim' => ['"status" IN (\'draft\', \'live\')', ['draft', 'live']];
        yield 'sqlite escaped quote' => ['"status" IN (\'draft\', \'it\'\'s\')', ['draft', "it's"]];
        yield 'sqlite member with comma and paren' => ['"c" IN (\'a)b\',\'c,d\')', ['a)b', 'c,d']];
        yield 'sqlite member with bracket' => ['"c" IN (\'a]b\', \'c\')', ['a]b', 'c']];

        // --- PostgreSQL: rewrites IN(...) into = ANY(ARRAY[...]) with per-member casts ---
        yield 'pg text column' => [
            'CHECK ((status = ANY (ARRAY[\'draft\'::text, \'live\'::text])))',
            ['draft', 'live'],
        ];
        yield 'pg escaped quote' => [
            'CHECK ((status = ANY (ARRAY[\'draft\'::text, \'it\'\'s\'::text])))',
            ['draft', "it's"],
        ];
        // A single member collapses to a plain equality — there is no ARRAY to find at all.
        yield 'pg single member collapses to equality' => [
            'CHECK ((single = \'only\'::text))',
            ['only'],
        ];
        // A VARCHAR column casts the column *and* the array, and the members to `character
        // varying` rather than `text`, so the cast-stripping cannot assume a single-word type.
        yield 'pg varchar double-casts' => [
            'CHECK (((vc)::text = ANY ((ARRAY[\'a\'::character varying, \'b\'::character varying])::text[])))',
            ['a', 'b'],
        ];
        yield 'pg member with comma and paren' => [
            'CHECK ((c = ANY (ARRAY[\'a)b\'::text, \'c,d\'::text])))',
            ['a)b', 'c,d'],
        ];
        yield 'pg member with bracket' => [
            'CHECK ((c = ANY (ARRAY[\'a]b\'::text, \'c\'::text])))',
            ['a]b', 'c'],
        ];

        // --- Not a member list: null, never a guess ---
        yield 'range check is not an enum' => ['CHECK ((qty >= 0))', null];
        yield 'ANY over expressions, not literals' => ['CHECK ((a = ANY (ARRAY[b, c])))', null];
        yield 'empty body' => ['', null];
        yield 'unterminated literal' => ['"c" IN (\'a', null];
    }

    #[DataProvider('bodies')]
    public function testParsesTheShapesEnginesActuallyProduce(string $body, ?array $expected): void
    {
        self::assertSame($expected, EnumCheckParser::members($body));
    }

    /**
     * Order is load-bearing, not incidental: the classifier calls a member change Safe only when
     * the live list is a *prefix* of the desired one (append-only growth). A parser that returned
     * the members as an unordered set would make every append look like an arbitrary rewrite and
     * classify it Destructive.
     */
    public function testMembersKeepTheirDeclaredOrder(): void
    {
        self::assertSame(
            ['gamma', 'alpha', 'beta'],
            EnumCheckParser::members('CHECK ((c = ANY (ARRAY[\'gamma\'::text, \'alpha\'::text, \'beta\'::text])))'),
        );
    }
}
