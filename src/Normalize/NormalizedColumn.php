<?php

declare(strict_types=1);

namespace Nandan108\AttrecordMigrations\Normalize;

/**
 * A normalization outcome: either a canonical {@see ColumnTuple}, or an explicit "unsure" with a
 * human-readable reason. The unsure branch is the design's escape valve (the design contract §4.2):
 * anything a normalizer cannot confidently reduce degrades to a Manual-classified diff — never to
 * a guessed ALTER.
 *
 * @psalm-suppress PossiblyUnusedProperty Public data surface — read by the differ and by consumers.
 *
 * @see https://github.com/Nandan108/attrecord/blob/main/docs/arch-migrations.md — the design contract this implements.
 */
final class NormalizedColumn
{
    private function __construct(
        public readonly ?ColumnTuple $tuple,
        public readonly ?string $unsureReason,
    ) {
    }

    public static function ok(ColumnTuple $tuple): self
    {
        return new self($tuple, null);
    }

    public static function unsure(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isUnsure(): bool
    {
        return null === $this->tuple;
    }
}
