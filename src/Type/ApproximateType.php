<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * An approximate numeric type, tag 3: its precision [PROTOCOL Data types].
 */
final readonly class ApproximateType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its precision, a u32. */
        public int $precision,
    ) {}
}
