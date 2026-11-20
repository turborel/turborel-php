<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A reference to a row of a typed table, its octets as the server gives them [PROTOCOL Values].
 */
final readonly class Reference implements SqlValue
{
    /** Makes it of `$octets`. */
    public function __construct(
        /** Its octets. */
        public string $octets,
    ) {}
}
