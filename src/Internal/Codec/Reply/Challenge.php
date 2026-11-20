<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Challenge 'C': the next step of an authentication exchange [PROTOCOL Challenge 'C'].
 *
 * @internal
 */
final readonly class Challenge implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The step's bytes. */
        public string $challenge,
    ) {}
}
