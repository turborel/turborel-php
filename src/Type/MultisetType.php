<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A multiset type, tag 22: its element type [PROTOCOL Data types].
 */
final readonly class MultisetType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its element type. */
        public DataType $element,
    ) {}
}
