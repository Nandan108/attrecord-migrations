<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Table;
use Nandan108\AttrecordMigrations\Ledger\SchemaStepRecord;

/** A host project renaming the run-once step ledger — see {@see CustomRunRecord}. */
#[Table(name: 'mig_custom_steps', primaryKey: 'step_key')]
final class CustomStepRecord extends SchemaStepRecord
{
}
