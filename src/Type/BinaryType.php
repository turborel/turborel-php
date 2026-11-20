<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * BINARY, tag 6, or BINARY VARYING, tag 7: its length [PROTOCOL Data types].
 */
final readonly class BinaryType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Whether it is BINARY VARYING. */
        public bool $varying,
        /** Its length, a u32. */
        public int $length,
    ) {}
}
