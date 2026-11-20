<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Status 'T': a setting of the session, as SQL writes its value, empty for a null [PROTOCOL Status 'T'].
 *
 * @internal
 */
final readonly class Status implements Reply
{
    /**
     * The reply of its fields.
     */
    public function __construct(
        /** The setting's name. */
        public string $name,
        /** Its value, as SQL writes it. */
        public string $value,
    ) {}
}
