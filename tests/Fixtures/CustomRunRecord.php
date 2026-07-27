<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\AttrecordMigrations\Ledger\SchemaRunRecord;

/**
 * A host project renaming the run ledger: only `#[Table]` (and the index, whose name is also
 * global) is restated — every column is inherited.
 */
#[Table(name: 'mig_custom_runs')]
#[Index('idx_custom_runs_started', columns: ['started_at'])]
final class CustomRunRecord extends SchemaRunRecord
{
}
