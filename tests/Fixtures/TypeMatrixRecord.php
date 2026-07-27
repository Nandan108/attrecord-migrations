<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Every **portable** `ColumnType` on one table, so the golden round-trip proves each one
 * normalizes identically on both sides of the diff — on all three backends. A type absent here is
 * a type whose normalization is unverified against a real engine, and therefore a candidate for a
 * silent false-positive ALTER at a consumer.
 *
 * Deliberately excluded (see {@see MysqlOnlyTypesRecord}, which covers them where they exist):
 * `Set` (no PG/SQLite equivalent — the producer throws), `Bit` (PG round-trip unmodeled, normalizes
 * "unsure" by design).
 *
 * Column-level notes, each a real engine constraint rather than a preference:
 * - text/blob families carry no default: MySQL forbids one on TEXT/BLOB.
 * - `Timestamp` is nullable: MariaDB runs with `explicit_defaults_for_timestamp` OFF, where a
 *   NOT NULL TIMESTAMP silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
 *   Nullable is the portable spelling that means what it says.
 * - float/double carry no default: engines re-render float literals, and pinning that is the
 *   normalizer's numeric-canon job (covered by the decimal columns), not this fixture's.
 */
#[Table(name: 'mig_type_matrix')]
final class TypeMatrixRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    // ---- integer families, both signednesses ----

    #[Column(ColumnType::TinyInt, default: -5)]
    public int $t_tinyint = -5;

    #[Column(ColumnType::SmallInt, nullable: true)]
    public ?int $t_smallint = null;

    #[Column(ColumnType::MediumInt, default: 0)]
    public int $t_mediumint = 0;

    #[Column(ColumnType::Int, default: 42)]
    public int $t_int = 42;

    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $t_bigint = null;

    #[Column(ColumnType::TinyIntUnsigned, default: 1)]
    public int $t_tinyint_u = 1;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $t_smallint_u = null;

    #[Column(ColumnType::MediumIntUnsigned, default: 0)]
    public int $t_mediumint_u = 0;

    #[Column(ColumnType::IntUnsigned, default: 7)]
    public int $t_int_u = 7;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $t_bigint_u = null;

    #[Column(ColumnType::Year, nullable: true)]
    public ?int $t_year = null;

    // ---- approximate + exact numerics ----

    #[Column(ColumnType::Float, nullable: true)]
    public ?float $t_float = null;

    #[Column(ColumnType::Double, nullable: true)]
    public ?float $t_double = null;

    #[Column(ColumnType::Decimal, precision: 12, scale: 4, default: '1.5000')]
    public string $t_decimal = '1.5000';

    #[Column(ColumnType::Bool, default: true)]
    public bool $t_bool = true;

    // ---- character + text families ----

    #[Column(ColumnType::Char, length: 8, default: 'ab')]
    public string $t_char = 'ab';

    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $t_varchar = null;

    #[Column(ColumnType::TinyText, nullable: true)]
    public ?string $t_tinytext = null;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $t_text = null;

    #[Column(ColumnType::MediumText, nullable: true)]
    public ?string $t_mediumtext = null;

    #[Column(ColumnType::LongText, nullable: true)]
    public ?string $t_longtext = null;

    // ---- binary ----

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $t_binary = null;

    #[Column(ColumnType::VarBinary, length: 64, nullable: true)]
    public ?string $t_varbinary = null;

    // ---- temporal ----

    #[Column(ColumnType::Date, default: '2020-01-01')]
    public string $t_date = '2020-01-01';

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $t_datetime = null;

    #[Column(ColumnType::Timestamp, nullable: true)]
    public ?\DateTimeImmutable $t_timestamp = null;

    #[Column(ColumnType::Json, nullable: true)]
    public ?array $t_json = null;

    #[Column(ColumnType::Enum, enumValues: ['a', 'b'], nullable: true)]
    public ?string $t_enum = null;
}
