<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\AttrecordMigrations\Live\LiveColumn;

/**
 * Reduces both sides of the diff — the attribute-derived desired column and the introspected live
 * column — to the dialect's canonical {@see ColumnTuple}. One implementation per dialect: the
 * desired side is a deterministic mapping of `ColumnType` to the dialect's storage family
 * (mirroring the dialect's own `renderColumnType()`), the live side parses the engine's catalog
 * strings. Both land in the same vocabulary, so tuple equality means "no drift".
 */
interface ColumnNormalizer
{
    public function normalizeDesired(ColumnDefinition $col): NormalizedColumn;

    public function normalizeLive(LiveColumn $col): NormalizedColumn;
}
