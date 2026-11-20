<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Rows 'B': a batch of rows [PROTOCOL Rows 'B'].
 *
 * @internal
 */
final readonly class Rows implements Reply
{
    /**
     * The reply of its fields.
     *
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        /** The rows, each a list of values. */
        public array $rows,
    ) {}
}
