<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * An array type, tag 16: its maximum cardinality, then its element type [PROTOCOL Data types].
 */
final readonly class ArrayType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its maximum cardinality, 1 to 65535. */
        public int $maxCardinality,
        /** Its element type. */
        public DataType $element,
    ) {}
}
