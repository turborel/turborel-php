<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * The type a column was declared with, beside the tag its values are
 * written by, granted `declared-types` [PROTOCOL Declared types]: INTEGER
 * of an exact numeric type of precision 10 and scale 0, say.
 */
final readonly class Declared
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name, as the Information Schema's DATA_TYPE gives it: INTEGER, DECIMAL, USER-DEFINED of a distinct type, or the name of any other type. */
        public string $name,
        /** The whole type as SQL writes it: INTEGER, DECIMAL(9,2), a distinct type's schema and name, or INTEGER ARRAY[3]. */
        public string $written,
    ) {}
}
