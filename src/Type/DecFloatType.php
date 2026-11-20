<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * DECFLOAT, tag 15: its precision [PROTOCOL Data types].
 */
final readonly class DecFloatType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its precision, a u32. */
        public int $precision,
    ) {}
}
