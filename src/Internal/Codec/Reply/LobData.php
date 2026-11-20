<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Lob 'L', the reply, 0 data: the octets a Lob read asked for [PROTOCOL Lob 'L'].
 *
 * @internal
 */
final readonly class LobData implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The octets read. */
        public string $data,
    ) {}
}
