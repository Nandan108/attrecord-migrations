<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

/**
 * Marks a Record that declares *some* of its table, not all of it: other code adds columns,
 * indexes or constraints to the same table at runtime, and the differ must leave them alone.
 *
 * The pipeline's default is the opposite, and deliberately so — anything live but undeclared is
 * reported as drift, because on a normal table it means someone changed the database behind the
 * schema's back. That default is wrong for a table whose shape is partly *computed*: a slot
 * registry that grows a column per registered dimension, a plugin's extension table, an EAV-ish
 * sidecar. There, undeclared is the normal state, and reporting it would either train the reader
 * to ignore the drift check or — with a `Destructive` ceiling — delete live data.
 *
 * Implementing this interface narrows the differ to **what the Record declares**: missing columns
 * and indexes are still added, declared ones still converge to their declared shape, but nothing
 * undeclared is ever proposed for dropping.
 *
 * The trade-off is real and one-directional: on such a table, a genuinely stray column left over
 * from an old version will never be surfaced. That is the fail-safe direction, and the reason
 * this is opt-in per Record rather than a global setting.
 *
 *     #[Table(name: 'invflux_slotspace')]
 *     final class SlotSpace extends Record implements PartiallyDeclared {}
 *
 * @api
 */
interface PartiallyDeclared
{
}
