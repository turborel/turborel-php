<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * What a completion counts, each where granted and it has something to
 * say: the commit's log position, tag 4; whether another statement's
 * replies follow, tag 5; the rows the statement counted, tag 6; and the
 * server's time, tag 18 [PROTOCOL Complete 'K'].
 *
 * @internal
 */
final readonly class Counts
{
    /** The counts of their parts. */
    public function __construct(
        /** The log position of the commit the statement made, a u64. */
        public int|string|null $position = null,
        /** Whether another statement's replies follow, of a Query of several. */
        public bool $more = false,
        /** The rows the statement inserted, updated, deleted or merged, or a query returned, a u64. */
        public int|string|null $rows = null,
        /** The server's time for the statement. */
        public ?Timing $time = null,
    ) {}
}
