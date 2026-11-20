<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Internal\Codec\Failure;

/**
 * Error 'E': a statement's failure, or the connection's [PROTOCOL Error 'E'].
 *
 * @internal
 */
final readonly class Error implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The failure. */
        public Failure $failure,
    ) {}
}
