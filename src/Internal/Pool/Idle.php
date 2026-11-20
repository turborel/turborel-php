<?php

declare(strict_types=1);

namespace Turborel\Internal\Pool;

use Turborel\Session\Connection;

/**
 * A connection idle in a pool: when it was made, and since when idle, in hrtime's nanoseconds.
 *
 * @internal
 */
final readonly class Idle
{
    /** The idle connection of its parts. */
    public function __construct(
        /** The connection. */
        public Connection $connection,
        /** When it was made. */
        public int $made,
        /** Since when it is idle. */
        public int $since,
    ) {}
}
