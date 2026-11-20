<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A field of a row type: its name and its type [PROTOCOL Data types].
 */
final readonly class Field
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name. */
        public string $name,
        /** Its type. */
        public DataType $type,
    ) {}
}
