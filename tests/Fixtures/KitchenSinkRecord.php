<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * A deliberately broad fixture: every structural feature the differ manages (producer parity —
 * arch-migrations.md §7) on one table, so introspection and the golden round-trip exercise the
 * full surface at once.
 */
#[Table(name: 'mig_kitchen_sink')]
#[Index('idx_status_created', columns: ['status', 'created_at'])]
#[ForeignKey(column: 'ref_id', references: RefTargetRecord::class, onDelete: ForeignKeyAction::SetNull)]
final class KitchenSinkRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_sku')]
    public string $sku = '';

    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $label = '';

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty = 0;

    #[Column(ColumnType::Int, nullable: true)]
    public ?int $delta = null;

    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $price = '0.00';

    #[Column(ColumnType::Enum, enumValues: ['draft', 'live', 'gone'], default: 'draft')]
    public string $status = 'draft';

    #[Column(ColumnType::Bool, default: false)]
    public bool $active = false;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $ref_id = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $seen_at = null;

    /**
     * Precision 0 + bare CURRENT_TIMESTAMP on purpose: `defaultExpr` is raw dialect SQL, and the
     * bare spelling is the one all three engines accept (SQLite rejects `CURRENT_TIMESTAMP(6)`;
     * MySQL requires the precision suffix to match a DATETIME(6) — so a precision-6 defaultExpr
     * cannot be written portably in one fixture).
     */
    #[Column(ColumnType::DateTime, defaultExpr: 'CURRENT_TIMESTAMP')]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::Json, nullable: true)]
    public ?array $meta = null;
}
