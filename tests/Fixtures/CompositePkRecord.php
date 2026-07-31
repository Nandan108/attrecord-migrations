<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * A DDL-only Record keyed on two columns — the shape that used to be undeclarable, and so had to
 * be hand-written DDL, and so was invisible to this package entirely.
 *
 * The shape this serves: a hot-path state table keyed `(a, b)`, read and written by raw SQL, whose
 * composite key doubles as the clustering key — so the key is load-bearing for performance and not
 * a candidate for replacement by a surrogate. Nothing here is ever saved through attrecord (the
 * CRUD paths refuse a composite-PK Record), so this fixture exists purely to be *described*,
 * converged, and diffed.
 *
 * @internal
 */
#[Table(name: 'mig_composite_state')]
#[PrimaryKey(columns: ['owner_id', 'item_id'])]
final class CompositePkRecord extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public int $owner_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $item_id = 0;

    #[Column(ColumnType::IntUnsigned, default: 0)]
    #[Index('idx_composite_qty')]
    public int $quantity = 0;
}
