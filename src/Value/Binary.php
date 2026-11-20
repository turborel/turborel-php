<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A binary string, of BINARY or BINARY VARYING [PROTOCOL Values]. A PHP string is octets already; this says they are a binary string, not characters.
 */
final readonly class Binary implements SqlValue
{
    /** Makes it of `$octets`. */
    public function __construct(
        /** Its octets. */
        public string $octets,
    ) {}
}
