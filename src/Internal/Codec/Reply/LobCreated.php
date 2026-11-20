<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Lob 'L', the reply, 1 locator: the locator a Lob create made [PROTOCOL Lob 'L'].
 *
 * @internal
 */
final readonly class LobCreated implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The locator, a u32. */
        public int $locator,
    ) {}
}
