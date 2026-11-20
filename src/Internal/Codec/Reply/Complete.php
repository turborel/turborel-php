<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Internal\Codec\Completion;

/**
 * Complete 'K': a statement's completion [PROTOCOL Complete 'K'].
 *
 * @internal
 */
final readonly class Complete implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The completion. */
        public Completion $completion,
    ) {}
}
