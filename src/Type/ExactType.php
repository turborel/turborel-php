<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * An exact numeric type, tag 2: its precision and scale [PROTOCOL Data types].
 */
final readonly class ExactType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its precision, a u32. */
        public int $precision,
        /** Its scale, a u32. */
        public int $scale,
    ) {}
}
