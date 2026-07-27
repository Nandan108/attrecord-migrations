<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations;

use Nandan108\Attrecord\Record;

/**
 * Two or more tables reference each other, so no creation order satisfies every foreign key —
 * attrecord emits FK constraints inline in `CREATE TABLE`, so whichever table is created first
 * points at one that does not exist yet.
 *
 * This is a **schema fact, not a pipeline limitation**, and it is raised rather than worked around:
 * breaking the loop needs a decision the pipeline cannot make for you — create one table without
 * its FK and add the constraint afterwards, which is what a hand-rolled installer does when it
 * defers exactly one edge of the cycle.
 */
final class CircularDependencyException extends \RuntimeException
{
    /** @param list<class-string<Record>> $cycle the loop, first class repeated implicitly at the end */
    public function __construct(public readonly array $cycle)
    {
        parent::__construct(\sprintf(
            'Circular foreign-key dependency: %s → %s. No creation order satisfies it while FKs are '
            .'emitted inline; create one of these tables without its foreign key and add the '
            .'constraint after both exist.',
            implode(' → ', $cycle),
            $cycle[0] ?? '?',
        ));
    }
}
