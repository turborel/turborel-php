<?php

declare(strict_types=1);

namespace Turborel\Statements;

/**
 * The server's time for a statement, in microseconds, with `row-counts`
 * [CLIENT What the server tells]: from when a worker began it until its
 * completion was ready, the time its request waited for a worker before
 * that, and the part spent waiting for locks.
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
