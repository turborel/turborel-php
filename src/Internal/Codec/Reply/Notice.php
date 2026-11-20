<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Internal\Codec\Diagnostics;

/**
 * Notice 'N': a condition a statement raised, or a notice of the server's own [PROTOCOL Notice 'N'].
 *
 * @internal
 */
final readonly class Notice implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** Its SQLSTATE. */
        public string $sqlstate,
        /** Its message. */
        public string $message,
        /** Its severity and what it is about, with `diagnostics`. */
        public ?Diagnostics $diagnostics,
    ) {}
}
