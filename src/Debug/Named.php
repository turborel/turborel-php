<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * A parameter or a variable of a frame: its name, its type as SQL writes
 * it, and its value [PROTOCOL Event 'W'] *(extension)*.
 */
final readonly class Named
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name. */
        public string $name,
        /** Its data type, as SQL writes it. */
        public string $type,
        /** Its value, as a row's value of its type is read. */
        public mixed $value,
    ) {}
}
