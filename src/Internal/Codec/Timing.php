<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * The server's time for a statement, tail tag 18, in microseconds: from
 * when a worker began it until its completion was ready, the time it
 * waited for a worker before that, and the part spent waiting for locks
 * [PROTOCOL Complete 'K'].
 *
 * @internal
 */
final readonly class Timing
{
    /** The times `$total`, `$queued` and `$locks`, each a u64. */
    public function __construct(
        /** From a worker's start until the completion was ready. */
        public int|string $total,
        /** Waiting for a worker, before that. */
        public int|string $queued,
        /** Waiting for locks, of the total. */
        public int|string $locks,
    ) {}
}
