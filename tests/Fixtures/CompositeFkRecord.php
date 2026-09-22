<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Tests\Fixtures;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * A child of {@see CompositePkRecord}, referencing it by the **whole** two-column key.
 *
 * The property under test is the ordinary one — a converged database re-plans empty — and for this
 * shape it is not a formality. The differ compares a desired foreign key against the live one as a
 * *shape*: local columns, target, referenced columns, actions. Until attrecord 0.23 the desired
 * side could only be one column, so `desiredFkShape()` wrapped a scalar in a one-element list. A
 * two-column key compared against that matches on no engine and reports drift forever, starting the
 * instant the table is created — exactly the failure `CompositePkRecord` was written for, one level
 * out.
 *
 * It also pins the part nobody can check by reading: that the constraint **name** attrecord derives
 * for a multi-column key is the name the engine reports back. The differ keys foreign keys by name,
 * so a derivation that disagrees with the catalogue looks like one key missing and another
 * undeclared — a drop and re-add on every single plan.
 *
 * @internal
 */
#[Table(name: 'mig_composite_fk_child')]
#[ForeignKey(
    column: ['parent_owner_id', 'parent_item_id'],
    references: CompositePkRecord::class,
    onDelete: ForeignKeyAction::Cascade,
)]
final class CompositeFkRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $parent_owner_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $parent_item_id = 0;
}
